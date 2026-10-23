<?php

namespace App\Domain\Ticketing;

use App\Domain\AuditLog\Auditor;
use App\Domain\Bookings\BookingService;
use App\Domain\Bookings\BookingStateMachine;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Enums\TicketingStatus;
use App\Domain\Bookings\Models\Booking;
use App\Domain\Bookings\Models\BookingPassenger;
use App\Domain\Finance\BookingLedger;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\BookingNotifier;
use App\Domain\Providers\Booking\Data\BookingRequest;
use App\Domain\Providers\Booking\Data\Fare;
use App\Domain\Providers\Booking\Data\ProviderBookingStatus;
use App\Domain\Providers\Booking\Data\TicketData;
use App\Domain\Providers\Booking\FlightProviderInterface;
use App\Domain\Providers\Exceptions\ProviderException;
use App\Domain\Providers\Exceptions\ProviderTimeoutException;
use App\Domain\Providers\ProviderManager;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Ticketing\Jobs\GenerateBookingDocuments;
use App\Domain\Ticketing\Jobs\VerifyTicketing;
use App\Domain\Ticketing\Models\Ticket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Charging and ticket issuance.
 *
 * 1. Inside a transaction with the booking row locked: verify state, charge the agency
 *    account (credit-limit checked under lock), mark the booking `confirmed`.
 * 2. Call the provider. Only a provider response containing ticket numbers makes the
 *    booking `ticketed`. A definitive failure reverses the charge. An unknown outcome
 *    (timeout) leaves the booking `confirmed` / `pending_verification` and a job verifies
 *    it with the provider - the charge stays until the provider says what happened.
 */
class TicketingService
{
    public function __construct(
        private readonly ProviderManager $providers,
        private readonly BookingStateMachine $states,
        private readonly BookingLedger $ledger,
        private readonly BookingService $bookings,
        private readonly Auditor $auditor,
        private readonly BookingNotifier $notifier,
    ) {}

    public function issue(Booking $booking, User $actor): Booking
    {
        $provider = $this->providers->get($booking->provider_code);
        if (! $provider->capabilities()->ticketing) {
            throw BusinessRuleException::make('TICKETING_UNSUPPORTED', __('errors.ticketing_unsupported'), 422);
        }

        $booking = DB::transaction(function () use ($booking, $actor, $provider) {
            $locked = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if ($locked->status->isCharged()) {
                return $locked; // already charged by a concurrent / repeated request
            }
            if (! in_array($locked->status, [BookingStatus::OnHold, BookingStatus::Pending], true)) {
                throw BusinessRuleException::make('BOOKING_NOT_ISSUABLE', __('errors.booking_not_issuable'), 409, ['status' => $locked->status->value]);
            }
            if ($locked->status === BookingStatus::Pending && $provider->capabilities()->hold) {
                throw BusinessRuleException::make('BOOKING_NOT_HELD', __('errors.booking_not_held'), 409);
            }
            if ($locked->status === BookingStatus::OnHold && $locked->isHoldExpired()) {
                throw BusinessRuleException::make('HOLD_EXPIRED', __('errors.hold_expired'), 409);
            }

            $transaction = $this->ledger->charge($locked, $actor);

            return $this->states->transition($locked, BookingStatus::Confirmed, 'charged', __('bookings.events.charged', [
                'amount' => $locked->charged_amount.' '.$locked->account_currency,
            ]), [
                'confirmed_at' => now(),
                'ticketing_status' => TicketingStatus::InProgress,
            ], ['ledger_transaction_id' => $transaction->id], $actor);
        });

        if ($booking->status !== BookingStatus::Confirmed || $booking->ticketing_status !== TicketingStatus::InProgress) {
            return $booking;
        }

        $this->auditor->record('booking.charged', $booking, new: ['amount' => $booking->charged_amount, 'currency' => $booking->account_currency]);

        if (! $booking->provider_booking_id) {
            $booking = $this->purchase($booking, $provider, $actor);
            if ($booking->ticketing_status !== TicketingStatus::InProgress) {
                return $booking;
            }
        }

        return $this->requestTickets($booking, $provider, $actor);
    }

    /** Re-check an unknown ticketing outcome with the provider (called by VerifyTicketing). */
    public function verify(Booking $booking, int $attempt): Booking
    {
        if ($booking->status !== BookingStatus::Confirmed || $booking->ticketing_status !== TicketingStatus::PendingVerification) {
            return $booking;
        }

        $provider = $this->providers->get($booking->provider_code);

        try {
            $snapshot = $booking->provider_booking_id
                ? $provider->retrieveBooking($booking->provider_booking_id)
                : $provider->findByClientReference($booking->reference);
        } catch (ProviderException $e) {
            return $this->scheduleVerification($booking, $attempt, $e->getMessage());
        }

        if ($snapshot === null) {
            return $this->fail($booking, null, 'PROVIDER_NO_RECORD', __('bookings.events.provider_no_record'));
        }

        if ($snapshot->status === ProviderBookingStatus::Ticketed && $snapshot->tickets) {
            if (! $booking->provider_booking_id) {
                $booking->forceFill(['provider_booking_id' => $snapshot->providerBookingId, 'pnr' => $snapshot->pnr])->save();
            }

            return $this->complete($booking, $snapshot->tickets, null);
        }

        if (in_array($snapshot->status, [ProviderBookingStatus::Held, ProviderBookingStatus::Cancelled, ProviderBookingStatus::Expired], true)) {
            return $this->fail($booking, null, 'TICKETING_NOT_COMPLETED', __('bookings.events.ticketing_not_completed', ['status' => $snapshot->status->value]));
        }

        return $this->scheduleVerification($booking, $attempt, "Provider status {$snapshot->status->value}");
    }

    /** Instant purchase for providers without hold support (agency already charged). */
    private function purchase(Booking $booking, FlightProviderInterface $provider, User $actor): Booking
    {
        try {
            $result = $provider->createBooking(new BookingRequest(
                providerOfferId: $booking->pricing['provider_offer_id'],
                clientReference: $booking->reference,
                passengers: $this->bookings->passengerDataFor($booking->load('passengers')),
                contactEmail: $booking->contact_email,
                contactPhone: $booking->contact_phone,
                expectedFare: Fare::of((string) $booking->supplier_base, (string) $booking->supplier_taxes, $booking->currency),
            ));
        } catch (ProviderTimeoutException $e) {
            return $this->markPendingVerification($booking, $e->getMessage());
        } catch (ProviderException $e) {
            return $this->fail($booking, $actor, $e->errorCode, $e->getMessage());
        }

        $booking->forceFill(['provider_booking_id' => $result->providerBookingId, 'pnr' => $result->pnr])->save();

        if ($result->status === ProviderBookingStatus::Ticketed && $result->tickets) {
            return $this->complete($booking, $result->tickets, $actor);
        }

        return $booking;
    }

    private function requestTickets(Booking $booking, FlightProviderInterface $provider, User $actor): Booking
    {
        try {
            $result = $provider->issueTickets($booking->provider_booking_id);
        } catch (ProviderTimeoutException $e) {
            return $this->markPendingVerification($booking, $e->getMessage());
        } catch (ProviderException $e) {
            return $this->fail($booking, $actor, $e->errorCode, $e->getMessage());
        }

        if ($result->issued && $result->tickets) {
            return $this->complete($booking, $result->tickets, $actor);
        }
        if ($result->pending) {
            return $this->markPendingVerification($booking, $result->message ?? 'Ticketing pending at provider');
        }

        return $this->fail($booking, $actor, 'TICKETING_NOT_ISSUED', $result->message ?? 'Provider did not issue tickets');
    }

    /** @param list<TicketData> $tickets */
    private function complete(Booking $booking, array $tickets, ?User $actor): Booking
    {
        $booking = DB::transaction(function () use ($booking, $tickets, $actor) {
            $locked = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            if ($locked->status === BookingStatus::Ticketed) {
                return $locked;
            }

            /** @var list<BookingPassenger> $passengers */
            $passengers = $locked->passengers()->get()->values()->all();
            if (count($tickets) !== count($passengers)) {
                throw BusinessRuleException::make('TICKET_COUNT_MISMATCH', __('errors.ticket_count_mismatch'), 502);
            }

            foreach ($tickets as $ticket) {
                $passenger = $passengers[$ticket->passengerIndex] ?? null;
                if (! $passenger || ! preg_match('/^\d{13}$/', $ticket->ticketNumber)) {
                    throw BusinessRuleException::make('INVALID_TICKET_DATA', __('errors.invalid_ticket_data'), 502);
                }

                Ticket::query()->create([
                    'booking_id' => $locked->id,
                    'tenant_id' => $locked->tenant_id,
                    'booking_passenger_id' => $passenger->id,
                    'ticket_number' => $ticket->ticketNumber,
                    'airline_code' => $ticket->airlineCode,
                    'status' => 'issued',
                    'is_test' => $locked->is_test,
                    'issued_at' => $ticket->issuedAt ? Carbon::parse($ticket->issuedAt) : now(),
                ]);
                $passenger->forceFill(['ticket_number' => $ticket->ticketNumber])->save();
            }

            return $this->states->transition($locked, BookingStatus::Ticketed, 'ticketed', __('bookings.events.ticketed', [
                'count' => count($tickets),
            ]), [
                'ticketing_status' => TicketingStatus::Issued,
                'ticketed_at' => now(),
            ], ['tickets' => array_map(fn (TicketData $t) => $t->ticketNumber, $tickets)], $actor, $actor ? 'user' : 'provider');
        });

        GenerateBookingDocuments::dispatch($booking->id)->afterCommit();
        $this->auditor->record('booking.ticketed', $booking, new: ['tickets' => count($tickets)], actor: $actor, actorType: $actor ? 'user' : 'provider');
        $this->notifier->ticketed($booking);

        return $booking;
    }

    /** Definitive provider failure: reverse the charge and release the booking. */
    private function fail(Booking $booking, ?User $actor, string $code, string $reason): Booking
    {
        $booking = DB::transaction(function () use ($booking, $actor, $code, $reason) {
            $locked = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            if ($locked->status !== BookingStatus::Confirmed) {
                return $locked;
            }

            $this->ledger->reverseCharge($locked, $reason, $actor);

            $holdStillValid = $locked->provider_booking_id && $locked->hold_expires_at?->isFuture();

            return $this->states->transition(
                $locked,
                $holdStillValid ? BookingStatus::OnHold : BookingStatus::Failed,
                'ticketing_failed',
                __('bookings.events.ticketing_failed', ['reason' => $reason]),
                [
                    'ticketing_status' => TicketingStatus::Failed,
                    'failure_code' => $code,
                    'failure_reason' => mb_substr($reason, 0, 1000),
                    'confirmed_at' => null,
                ],
                ['code' => $code],
                $actor,
                'provider',
            );
        });

        $this->auditor->record('booking.ticketing_failed', $booking, new: ['code' => $code, 'charge_reversed' => true], actor: $actor, actorType: 'provider');
        $this->notifier->ticketingFailed($booking);

        return $booking;
    }

    private function markPendingVerification(Booking $booking, string $message): Booking
    {
        $booking->forceFill(['ticketing_status' => TicketingStatus::PendingVerification])->save();
        $this->states->record($booking, 'ticketing_pending_verification', __('bookings.events.ticketing_pending_verification'), ['message' => $message], actorType: 'provider');
        VerifyTicketing::dispatch($booking->id, 1)->delay(now()->addSeconds(30));

        return $booking;
    }

    private function scheduleVerification(Booking $booking, int $attempt, string $message): Booking
    {
        if ($attempt >= (int) config('platform.bookings.ticketing_verification_attempts')) {
            $booking->forceFill(['ticketing_status' => TicketingStatus::NeedsAttention])->save();
            $this->states->record($booking, 'ticketing_needs_attention', __('bookings.events.ticketing_needs_attention'), ['message' => $message], actorType: 'system');
            $this->auditor->record('booking.ticketing_needs_attention', $booking, metadata: ['message' => $message], actorType: 'system');

            return $booking;
        }

        VerifyTicketing::dispatch($booking->id, $attempt + 1)->delay(now()->addSeconds(min(3600, 30 * (2 ** $attempt))));

        return $booking;
    }
}
