<?php

namespace App\Domain\Bookings;

use App\Domain\AuditLog\Auditor;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Enums\PassengerType;
use App\Domain\Bookings\Jobs\ReconcileBooking;
use App\Domain\Bookings\Models\Booking;
use App\Domain\Bookings\Models\BookingPassenger;
use App\Domain\Commissions\CurrencyConverter;
use App\Domain\Finance\AccountService;
use App\Domain\FlightSearch\Models\FlightOffer;
use App\Domain\FlightSearch\OfferPricingService;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\BookingNotifier;
use App\Domain\Providers\Booking\Data\BookingRequest;
use App\Domain\Providers\Booking\Data\Fare;
use App\Domain\Providers\Booking\Data\PassengerData;
use App\Domain\Providers\Booking\Data\ProviderBookingResult;
use App\Domain\Providers\Booking\Data\ProviderBookingStatus;
use App\Domain\Providers\Booking\FlightProviderInterface;
use App\Domain\Providers\Exceptions\ProviderException;
use App\Domain\Providers\Exceptions\ProviderTimeoutException;
use App\Domain\Providers\ProviderManager;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Shared\Money\Money;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Ticketing\Jobs\GenerateBookingDocuments;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingService
{
    public function __construct(
        private readonly OfferPricingService $pricing,
        private readonly ProviderManager $providers,
        private readonly BookingStateMachine $states,
        private readonly AccountService $accounts,
        private readonly CurrencyConverter $fx,
        private readonly Auditor $auditor,
        private readonly BookingNotifier $notifier,
    ) {}

    /**
     * Re-price the offer, check the agency's credit, create the booking and - when the
     * provider supports it - place a hold. Idempotent per (tenant, idempotency key).
     *
     * @param  list<array<string, mixed>>  $passengers  validated passenger payloads
     * @param  array{email: string, phone?: string|null}  $contact
     */
    public function create(User $user, FlightOffer $offer, array $passengers, array $contact, string $expectedTotal, string $idempotencyKey): Booking
    {
        if ($existing = $this->findByKey($user->tenant_id, $idempotencyKey)) {
            return $existing;
        }

        $passengerData = $this->validatePassengers($offer, $passengers);

        $check = $this->pricing->recheck($offer);
        if (! $check['current_total']->equals(Money::of($expectedTotal, $offer->currency))) {
            throw BusinessRuleException::make('PRICE_CHANGED', __('errors.price_changed'), 409, [
                'expected_total' => Money::of($expectedTotal, $offer->currency)->jsonSerialize(),
                'current_total' => $check['current_total']->jsonSerialize(),
                'previous_total' => $check['previous_total']->jsonSerialize(),
            ]);
        }
        $offer = $check['offer'];

        $tenant = Tenant::query()->findOrFail($user->tenant_id);
        $wallet = $this->accounts->walletFor($tenant);
        $this->accounts->assertCanSpend($wallet, $this->fx->convert(
            Money::of((string) $offer->pricing['agency_net'], $offer->currency),
            $wallet->currency,
        ));

        $provider = $this->providers->get($offer->provider_code);

        try {
            $booking = DB::transaction(fn () => $this->persist($user, $tenant, $offer, $passengers, $contact, $idempotencyKey, $provider));
        } catch (UniqueConstraintViolationException) {
            return $this->findByKey($user->tenant_id, $idempotencyKey)
                ?? throw BusinessRuleException::make('DUPLICATE_REQUEST', __('errors.duplicate_request'), 409);
        }

        $this->auditor->record('booking.created', $booking, new: [
            'reference' => $booking->reference, 'offer' => $offer->id, 'sale_total' => $booking->sale_total, 'currency' => $booking->currency,
        ]);

        if (! $provider->capabilities()->hold) {
            $this->states->record($booking, 'awaiting_ticketing', __('bookings.events.awaiting_ticketing'), actor: $user);

            return $booking;
        }

        return $this->placeHold($booking, $provider, $passengerData, $user);
    }

    /** Apply the provider's view of a booking created by createBooking()/reconciliation. */
    public function applyHoldResult(Booking $booking, ProviderBookingResult $result, ?User $actor): Booking
    {
        if ($result->status !== ProviderBookingStatus::Held) {
            return $this->states->transition($booking, BookingStatus::Failed, 'hold_failed', __('bookings.events.unexpected_provider_status', ['status' => $result->status->value]), [
                'provider_booking_id' => $result->providerBookingId,
                'failure_code' => 'UNEXPECTED_PROVIDER_STATUS',
                'failure_reason' => "Provider returned status {$result->status->value} when a hold was expected",
            ], actor: $actor, actorType: 'provider');
        }

        $expires = $result->holdExpiresAt ? Carbon::parse($result->holdExpiresAt) : null;

        $booking = $this->states->transition($booking, BookingStatus::OnHold, 'hold_created', __('bookings.events.hold_created', [
            'pnr' => $result->pnr,
            'expires' => $expires?->toDateTimeString() ?? '-',
        ]), [
            'provider_booking_id' => $result->providerBookingId,
            'pnr' => $result->pnr,
            'hold_expires_at' => $expires,
            'ticketing_deadline' => $result->ticketingDeadline ? Carbon::parse($result->ticketingDeadline) : $expires,
        ], ['pnr' => $result->pnr], $actor, $actor ? 'user' : 'provider');

        GenerateBookingDocuments::dispatch($booking->id)->afterCommit();
        $this->notifier->held($booking);

        return $booking;
    }

    /** @param list<PassengerData> $passengers */
    private function placeHold(Booking $booking, FlightProviderInterface $provider, array $passengers, User $user): Booking
    {
        try {
            $result = $provider->createBooking(new BookingRequest(
                providerOfferId: $booking->pricing['provider_offer_id'],
                clientReference: $booking->reference,
                passengers: $passengers,
                contactEmail: $booking->contact_email,
                contactPhone: $booking->contact_phone,
                expectedFare: Fare::of((string) $booking->supplier_base, (string) $booking->supplier_taxes, $booking->currency),
            ));
        } catch (ProviderTimeoutException $e) {
            $this->states->record($booking, 'provider_timeout', __('bookings.events.provider_timeout'), ['error' => $e->getMessage()], actorType: 'provider');
            ReconcileBooking::dispatch($booking->id)->delay(now()->addSeconds(30));

            return $booking->refresh();
        } catch (ProviderException $e) {
            $booking = $this->states->transition($booking, BookingStatus::Failed, 'hold_failed', __('bookings.events.hold_failed', ['reason' => $e->getMessage()]), [
                'failure_code' => $e->errorCode,
                'failure_reason' => $e->getMessage(),
            ], actor: $user, actorType: 'provider');
            $this->notifier->failed($booking);

            return $booking;
        }

        return $this->applyHoldResult($booking, $result, $user);
    }

    /**
     * @param  list<array<string, mixed>>  $passengers
     * @param  array{email: string, phone?: string|null}  $contact
     */
    private function persist(User $user, Tenant $tenant, FlightOffer $offer, array $passengers, array $contact, string $key, FlightProviderInterface $provider): Booking
    {
        $pricing = $offer->pricing;

        $booking = Booking::query()->create([
            'tenant_id' => $tenant->id,
            'reference' => $this->newReference(),
            'created_by' => $user->id,
            'flight_offer_id' => $offer->id,
            'provider_code' => $offer->provider_code,
            'is_test' => $offer->is_test || $provider->isSandbox(),
            'status' => BookingStatus::Pending,
            'trip_type' => $offer->search->trip_type,
            'cabin' => $offer->cabin,
            'itinerary' => $offer->itinerary,
            'baggage' => $offer->baggage,
            'fare_rules' => $offer->fare_rules,
            'refundable' => $offer->refundable,
            'passenger_counts' => $offer->passengers,
            'contact_email' => $contact['email'],
            'contact_phone' => $contact['phone'] ?? null,
            'currency' => $offer->currency,
            'supplier_base' => $offer->supplier_base,
            'supplier_taxes' => $offer->supplier_taxes,
            'supplier_total' => $offer->supplier_total,
            'markup_amount' => $pricing['markup'],
            'sale_total' => $pricing['sale_total'],
            'commission_amount' => $pricing['commission'],
            'agency_net' => $pricing['agency_net'],
            'platform_profit' => $pricing['platform_profit'],
            'pricing' => $pricing + ['provider_offer_id' => $offer->provider_offer_id],
            'account_currency' => $tenant->default_currency,
            'idempotency_key' => $key,
        ]);

        foreach ($passengers as $p) {
            BookingPassenger::query()->create([
                'booking_id' => $booking->id,
                'tenant_id' => $tenant->id,
                'traveler_id' => $p['traveler_id'] ?? null,
                'type' => $p['type'],
                'title' => $p['title'] ?? null,
                'first_name' => Str::upper($p['first_name']),
                'last_name' => Str::upper($p['last_name']),
                'gender' => $p['gender'],
                'date_of_birth' => $p['date_of_birth'],
                'nationality' => Str::upper($p['nationality']),
                'document_type' => $p['document_type'] ?? 'passport',
                'document_number' => Str::upper($p['document_number']),
                'document_expiry' => $p['document_expiry'],
                'document_country' => Str::upper($p['document_country']),
                'email' => $p['email'] ?? null,
                'phone' => $p['phone'] ?? null,
            ]);
        }

        $this->states->record($booking, 'created', __('bookings.events.created', ['route' => $booking->route()]), [
            'sale_total' => $booking->sale_total, 'currency' => $booking->currency,
        ], $user);

        return $booking;
    }

    /**
     * @param  list<array<string, mixed>>  $passengers
     * @return list<PassengerData>
     */
    private function validatePassengers(FlightOffer $offer, array $passengers): array
    {
        $expected = $offer->passengers;
        $counts = ['adults' => 0, 'children' => 0, 'infants' => 0];
        $lastSlice = $offer->itinerary[count($offer->itinerary) - 1];
        $lastTravel = Carbon::parse($lastSlice['arrival_at'] ?? $lastSlice['departure_at']);
        $firstTravel = Carbon::parse($offer->itinerary[0]['departure_at']);
        $errors = [];

        foreach ($passengers as $i => $p) {
            $type = PassengerType::from($p['type']);
            $counts[$type->countKey()]++;

            if (! $type->acceptsAge(Carbon::parse($p['date_of_birth']), $lastTravel)) {
                $errors["passengers.{$i}.date_of_birth"] = __('errors.passenger_age_mismatch', ['type' => $type->value]);
            }
            if (Carbon::parse($p['document_expiry'])->lessThanOrEqualTo($lastTravel)) {
                $errors["passengers.{$i}.document_expiry"] = __('errors.document_expires_before_travel');
            }
            if (Carbon::parse($p['date_of_birth'])->greaterThan($firstTravel)) {
                $errors["passengers.{$i}.date_of_birth"] = __('errors.passenger_not_born');
            }
        }

        if ($counts !== ['adults' => (int) $expected['adults'], 'children' => (int) $expected['children'], 'infants' => (int) $expected['infants']]) {
            $errors['passengers'] = __('errors.passenger_count_mismatch');
        }
        if ($counts['infants'] > $counts['adults']) {
            $errors['passengers'] = __('errors.too_many_infants');
        }

        if ($errors) {
            throw BusinessRuleException::make('INVALID_PASSENGERS', __('errors.invalid_passengers'), 422, ['errors' => $errors]);
        }

        return array_map(fn (array $p) => new PassengerData(
            type: PassengerType::from($p['type']),
            title: $p['title'] ?? null,
            firstName: Str::upper($p['first_name']),
            lastName: Str::upper($p['last_name']),
            gender: $p['gender'],
            dateOfBirth: $p['date_of_birth'],
            nationality: Str::upper($p['nationality']),
            documentType: $p['document_type'] ?? 'passport',
            documentNumber: Str::upper($p['document_number']),
            documentExpiry: $p['document_expiry'],
            documentCountry: Str::upper($p['document_country']),
        ), array_values($passengers));
    }

    /** Passenger payloads for provider calls made after creation (instant purchase, reconciliation). */
    public function passengerDataFor(Booking $booking): array
    {
        return $booking->passengers->map(fn (BookingPassenger $p) => new PassengerData(
            type: $p->type,
            title: $p->title,
            firstName: $p->first_name,
            lastName: $p->last_name,
            gender: $p->gender,
            dateOfBirth: $p->date_of_birth->toDateString(),
            nationality: $p->nationality,
            documentType: $p->document_type,
            documentNumber: $p->document_number,
            documentExpiry: $p->document_expiry->toDateString(),
            documentCountry: $p->document_country,
        ))->all();
    }

    private function findByKey(?int $tenantId, string $key): ?Booking
    {
        return Booking::query()->where('tenant_id', $tenantId)->where('idempotency_key', $key)->first();
    }

    private function newReference(): string
    {
        $prefix = config('platform.bookings.reference_prefix');
        do {
            $reference = $prefix.'-'.Str::upper(Str::random(6));
            $reference = strtr($reference, ['0' => '7', 'O' => 'Q', 'I' => 'K', '1' => '8']);
        } while (Booking::query()->withoutGlobalScopes()->where('reference', $reference)->exists());

        return $reference;
    }
}
