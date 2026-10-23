<?php

namespace App\Domain\Bookings;

use App\Domain\AuditLog\Auditor;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Enums\TicketingStatus;
use App\Domain\Bookings\Models\Booking;
use App\Domain\Finance\BookingLedger;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\BookingNotifier;
use App\Domain\Providers\Exceptions\ProviderException;
use App\Domain\Providers\ProviderManager;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Cancellation before ticketing. The provider reservation is released first; only when the
 * provider confirms is the booking cancelled (and any charge reversed). Ticketed bookings
 * go through RefundService instead.
 */
class CancellationService
{
    public function __construct(
        private readonly ProviderManager $providers,
        private readonly BookingStateMachine $states,
        private readonly BookingLedger $ledger,
        private readonly Auditor $auditor,
        private readonly BookingNotifier $notifier,
    ) {}

    public function cancel(Booking $booking, User $actor, string $reason): Booking
    {
        $this->assertCancellable($booking);

        if ($booking->provider_booking_id) {
            try {
                $result = $this->providers->get($booking->provider_code)->cancelBooking($booking->provider_booking_id);
            } catch (ProviderException $e) {
                $this->states->record($booking, 'cancel_failed', __('bookings.events.cancel_failed', ['reason' => $e->getMessage()]), ['code' => $e->errorCode], $actor, 'provider');
                throw BusinessRuleException::make('CANCEL_FAILED', __('errors.cancel_failed'), 502, ['provider_message' => $e->getMessage()]);
            }

            if (! $result->cancelled) {
                throw BusinessRuleException::make('CANCEL_FAILED', __('errors.cancel_failed'), 502, ['provider_message' => $result->message]);
            }
        }

        $booking = DB::transaction(function () use ($booking, $actor, $reason) {
            $locked = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            if ($locked->status === BookingStatus::Cancelled) {
                return $locked;
            }
            $this->assertCancellable($locked);

            $reversed = $locked->status === BookingStatus::Confirmed
                ? $this->ledger->reverseCharge($locked, $reason, $actor)
                : null;

            return $this->states->transition($locked, BookingStatus::Cancelled, 'cancelled', __('bookings.events.cancelled', ['reason' => $reason]), [
                'cancelled_at' => now(),
                'cancellation_reason' => mb_substr($reason, 0, 500),
            ], ['charge_reversed' => $reversed !== null], $actor);
        });

        $this->auditor->record('booking.cancelled', $booking, new: ['reason' => $reason]);
        $this->notifier->cancelled($booking);

        return $booking;
    }

    private function assertCancellable(Booking $booking): void
    {
        if ($booking->status === BookingStatus::Ticketed) {
            throw BusinessRuleException::make('USE_REFUND', __('errors.use_refund'), 409);
        }

        if ($booking->status === BookingStatus::Confirmed
            && in_array($booking->ticketing_status, [TicketingStatus::InProgress, TicketingStatus::PendingVerification], true)) {
            throw BusinessRuleException::make('TICKETING_IN_PROGRESS', __('errors.ticketing_in_progress'), 409);
        }

        if (! in_array($booking->status, [BookingStatus::Pending, BookingStatus::OnHold, BookingStatus::Confirmed], true)) {
            throw BusinessRuleException::make('BOOKING_NOT_CANCELLABLE', __('errors.booking_not_cancellable'), 409, ['status' => $booking->status->value]);
        }
    }
}
