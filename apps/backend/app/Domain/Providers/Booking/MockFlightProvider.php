<?php

namespace App\Domain\Providers\Booking;

use App\Domain\FlightSearch\Enums\CabinClass;
use App\Domain\Providers\Booking\Data\BookingRequest;
use App\Domain\Providers\Booking\Data\CancelResult;
use App\Domain\Providers\Booking\Data\Fare;
use App\Domain\Providers\Booking\Data\FlightOfferData;
use App\Domain\Providers\Booking\Data\FlightSegment;
use App\Domain\Providers\Booking\Data\FlightSlice;
use App\Domain\Providers\Booking\Data\PassengerCounts;
use App\Domain\Providers\Booking\Data\PriceCheckResult;
use App\Domain\Providers\Booking\Data\ProviderBookingResult;
use App\Domain\Providers\Booking\Data\ProviderBookingStatus;
use App\Domain\Providers\Booking\Data\ProviderCapabilities;
use App\Domain\Providers\Booking\Data\RefundResult;
use App\Domain\Providers\Booking\Data\SearchCriteria;
use App\Domain\Providers\Booking\Data\SearchResult;
use App\Domain\Providers\Booking\Data\SearchSlice;
use App\Domain\Providers\Booking\Data\TicketData;
use App\Domain\Providers\Booking\Data\TicketingResult;
use App\Domain\Providers\Exceptions\ProviderAuthenticationException;
use App\Domain\Providers\Exceptions\ProviderRejectedException;
use App\Domain\Providers\Exceptions\ProviderTimeoutException;
use App\Domain\Providers\Exceptions\ProviderUnavailableException;
use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;

/**
 * SIMULATED booking provider for development, QA and automated tests.
 *
 * Everything it returns is test data: offers are flagged is_test, flight numbers are in the
 * 9000-9999 range, PNRs are random and ticket numbers use the unassigned "000" airline prefix.
 * It must never be enabled for real sales (config/providers.php disables it in production).
 *
 * Behaviour can be switched per provider record (`settings.scenario`) to exercise failure paths:
 *   normal | no_results | search_error | auth_error | price_change | unavailable |
 *   hold_rejected | hold_timeout | ticketing_failed | ticketing_timeout | ticketing_pending |
 *   refund_pending | cancel_failed
 */
class MockFlightProvider implements FlightProviderInterface
{
    public const CODE = 'mock';

    private const CURRENCY = 'USD';

    private const UTC_OFFSET = '+03:00';

    private const TTL_SECONDS = 7 * 24 * 3600;

    /** Real carrier codes for display/enrichment only; schedules and fares are simulated. */
    private const CARRIERS = [
        'IA' => ['hub' => 'BGW', 'aircraft' => 'B738'],
        'FZ' => ['hub' => 'DXB', 'aircraft' => 'B38M'],
        'EK' => ['hub' => 'DXB', 'aircraft' => 'B77W'],
        'QR' => ['hub' => 'DOH', 'aircraft' => 'A359'],
        'TK' => ['hub' => 'IST', 'aircraft' => 'A321'],
        'RJ' => ['hub' => 'AMM', 'aircraft' => 'A320'],
        'GF' => ['hub' => 'BAH', 'aircraft' => 'A320'],
    ];

    /** @param array<string, mixed> $settings */
    public function __construct(
        private readonly Cache $cache,
        private readonly array $settings = [],
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function isSandbox(): bool
    {
        return true;
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            search: true, priceCheck: true, hold: true, retrieve: true, ticketing: true,
            cancel: true, refund: true, multiCity: true, roundTrip: true,
        );
    }

    public function healthCheck(): bool
    {
        return $this->scenario() !== 'search_error';
    }

    public function search(SearchCriteria $criteria): SearchResult
    {
        match ($this->scenario()) {
            'search_error' => throw ProviderUnavailableException::for(self::CODE, 'Simulated provider outage', 503),
            'auth_error' => throw ProviderAuthenticationException::for(self::CODE, 401),
            default => null,
        };

        if ($this->scenario() === 'no_results') {
            return new SearchResult([]);
        }

        $seed = json_encode($criteria->toArray());
        $offers = [];
        $carrierCodes = array_keys(self::CARRIERS);
        $count = $this->n($seed.'count', 4, 7);

        for ($i = 0; $i < $count; $i++) {
            $carrier = $carrierCodes[$this->n($seed.'carrier'.$i, 0, count($carrierCodes) - 1)];
            $slices = [];
            foreach ($criteria->slices as $index => $slice) {
                $slices[] = $this->buildSlice($slice, $carrier, "{$seed}|{$i}|{$index}");
            }

            $fare = $this->buildFare($criteria->passengers, $criteria->cabin, count($slices), $slices, "{$seed}|{$i}");
            $offerId = 'MOCK-'.Str::upper(Str::random(16));
            $refundable = $this->n($seed.'refundable'.$i, 0, 2) === 0;

            $offer = new FlightOfferData(
                providerCode: self::CODE,
                providerOfferId: $offerId,
                slices: $slices,
                fare: $fare,
                cabin: $criteria->cabin,
                validatingAirline: $carrier,
                baggage: $this->baggage($criteria->cabin, $carrier),
                refundable: $refundable,
                fareRules: [
                    'refundable' => $refundable,
                    'change_penalty' => $refundable ? '25.00 USD' : '75.00 USD',
                    'cancellation_penalty' => $refundable ? '10%' : 'base fare not refundable',
                ],
                expiresAt: now()->addMinutes(30)->toIso8601String(),
                isTest: true,
                seatsRemaining: $this->n($seed.'seats'.$i, 1, 9),
            );

            $this->cache->put($this->key('offer', $offerId), [
                'fare' => $fare->toArray(),
                'passengers' => $criteria->passengers->toArray(),
                'refundable' => $refundable,
                'carrier' => $carrier,
                'first_departure' => $slices[0]->segments[0]->departureAt,
            ], self::TTL_SECONDS);

            $offers[] = $offer;
        }

        return new SearchResult($offers);
    }

    public function priceCheck(string $providerOfferId): PriceCheckResult
    {
        $stored = $this->cache->get($this->key('offer', $providerOfferId));
        if (! $stored || $this->scenario() === 'unavailable') {
            return PriceCheckResult::unavailable('Seats are no longer available for this offer (simulated)');
        }

        $fare = Fare::of($stored['fare']['base'], $stored['fare']['taxes'], $stored['fare']['currency']);
        if ($this->scenario() === 'price_change') {
            $fare = new Fare($fare->base->percent(105), $fare->taxes);
        }

        return new PriceCheckResult(true, $fare, $providerOfferId, now()->addMinutes(20)->toIso8601String());
    }

    public function createBooking(BookingRequest $request): ProviderBookingResult
    {
        if ($this->scenario() === 'hold_rejected') {
            throw ProviderRejectedException::for(self::CODE, 'Airline rejected the reservation (simulated)', 'HOLD_REJECTED');
        }

        $offer = $this->cache->get($this->key('offer', $request->providerOfferId));
        if (! $offer) {
            throw ProviderRejectedException::for(self::CODE, 'Offer expired at provider', 'OFFER_EXPIRED');
        }

        $id = 'MB-'.Str::upper(Str::random(12));
        $departure = CarbonImmutable::parse($offer['first_departure']);
        $holdExpires = CarbonImmutable::now()->addHours(2)->min($departure->subHours(3));

        $booking = [
            'id' => $id,
            'pnr' => $this->pnr(),
            'status' => ProviderBookingStatus::Held->value,
            'client_reference' => $request->clientReference,
            'hold_expires_at' => $holdExpires->toIso8601String(),
            'fare' => $offer['fare'],
            'refundable' => $offer['refundable'],
            'carrier' => $offer['carrier'],
            'passenger_count' => count($request->passengers),
            'tickets' => [],
        ];
        $this->save($booking);

        if ($this->scenario() === 'hold_timeout') {
            throw ProviderTimeoutException::for(self::CODE, 'createBooking');
        }

        return $this->toResult($booking);
    }

    public function retrieveBooking(string $providerBookingId): ProviderBookingResult
    {
        return $this->toResult($this->load($providerBookingId));
    }

    public function findByClientReference(string $clientReference): ?ProviderBookingResult
    {
        $id = $this->cache->get($this->key('ref', $clientReference));

        return $id ? $this->retrieveBooking($id) : null;
    }

    public function issueTickets(string $providerBookingId): TicketingResult
    {
        $booking = $this->load($providerBookingId);

        if ($booking['status'] === ProviderBookingStatus::Ticketed->value) {
            return new TicketingResult(true, $this->tickets($booking));
        }
        if ($booking['status'] !== ProviderBookingStatus::Held->value || $this->isExpired($booking)) {
            throw ProviderRejectedException::for(self::CODE, 'Booking is not in a ticketable state', 'NOT_TICKETABLE');
        }
        if ($this->scenario() === 'ticketing_failed') {
            throw ProviderRejectedException::for(self::CODE, 'Ticketing declined by airline (simulated)', 'TICKETING_DECLINED');
        }

        $booking['tickets'] = [];
        for ($i = 0; $i < $booking['passenger_count']; $i++) {
            $booking['tickets'][] = ['index' => $i, 'number' => '000'.random_int(1000000000, 9999999999), 'airline' => $booking['carrier']];
        }

        if ($this->scenario() === 'ticketing_pending') {
            $booking['status'] = ProviderBookingStatus::Confirmed->value;
            $booking['tickets_on_retrieve'] = true;
            $this->save($booking);

            return new TicketingResult(false, [], pending: true, message: 'Ticketing queued at airline (simulated)');
        }

        $booking['status'] = ProviderBookingStatus::Ticketed->value;
        $this->save($booking);

        if ($this->scenario() === 'ticketing_timeout') {
            throw ProviderTimeoutException::for(self::CODE, 'issueTickets');
        }

        return new TicketingResult(true, $this->tickets($booking));
    }

    public function cancelBooking(string $providerBookingId): CancelResult
    {
        $booking = $this->load($providerBookingId);

        if ($this->scenario() === 'cancel_failed') {
            throw ProviderUnavailableException::for(self::CODE, 'Cancellation service unavailable (simulated)', 503);
        }
        if ($booking['status'] === ProviderBookingStatus::Ticketed->value) {
            throw ProviderRejectedException::for(self::CODE, 'Ticketed bookings must be refunded, not cancelled', 'ALREADY_TICKETED');
        }

        $booking['status'] = ProviderBookingStatus::Cancelled->value;
        $this->save($booking);

        return new CancelResult(true);
    }

    public function refund(string $providerBookingId): RefundResult
    {
        $booking = $this->load($providerBookingId);
        if ($booking['status'] === ProviderBookingStatus::Refunded->value) {
            return $this->refundResult($booking);
        }
        if ($booking['status'] !== ProviderBookingStatus::Ticketed->value) {
            throw ProviderRejectedException::for(self::CODE, 'Only ticketed bookings can be refunded', 'NOT_REFUNDABLE');
        }

        if ($this->scenario() === 'refund_pending') {
            $booking['refund_status'] = 'pending';
            $this->save($booking);

            return new RefundResult('pending', providerRefundId: 'MR-'.$booking['id'], message: 'Refund submitted to airline (simulated)');
        }

        $booking['status'] = ProviderBookingStatus::Refunded->value;
        $this->save($booking);

        return $this->refundResult($booking);
    }

    // ------------------------------------------------------------------ internals

    private function scenario(): string
    {
        return (string) ($this->settings['scenario'] ?? 'normal');
    }

    private function buildSlice(SearchSlice $slice, string $carrier, string $seed): FlightSlice
    {
        $hub = self::CARRIERS[$carrier]['hub'];
        $aircraft = self::CARRIERS[$carrier]['aircraft'];
        $direct = $hub === $slice->origin || $hub === $slice->destination || $this->n($seed.'direct', 0, 2) === 0;

        $departure = CarbonImmutable::parse($slice->date.'T00:00:00'.self::UTC_OFFSET)
            ->addMinutes($this->n($seed.'dep', 5 * 12, 22 * 12) * 5);

        $legs = $direct ? [[$slice->origin, $slice->destination]] : [[$slice->origin, $hub], [$hub, $slice->destination]];
        $segments = [];
        $cursor = $departure;
        foreach ($legs as $l => [$from, $to]) {
            if ($l > 0) {
                $cursor = $cursor->addMinutes($this->n($seed.'layover', 12, 48) * 5);
            }
            $duration = $this->n($seed.'dur'.$l, 14, 66) * 5;
            $arrival = $cursor->addMinutes($duration);
            $segments[] = new FlightSegment(
                marketingCarrier: $carrier,
                flightNumber: $carrier.(9000 + $this->n($seed.'fn'.$l, 0, 999)),
                origin: $from,
                destination: $to,
                departureAt: $cursor->toIso8601String(),
                arrivalAt: $arrival->toIso8601String(),
                durationMinutes: $duration,
                aircraft: $aircraft,
                bookingClass: 'Y',
            );
            $cursor = $arrival;
        }

        return new FlightSlice($segments, (int) $departure->diffInMinutes($cursor));
    }

    /** @param list<FlightSlice> $slices */
    private function buildFare(PassengerCounts $pax, CabinClass $cabin, int $sliceCount, array $slices, string $seed): Fare
    {
        $multiplier = match ($cabin) {
            CabinClass::Economy => '1',
            CabinClass::PremiumEconomy => '1.6',
            CabinClass::Business => '3',
            CabinClass::First => '5',
        };
        $stops = array_sum(array_map(fn (FlightSlice $s) => $s->stops(), $slices));
        $adultBase = Money::of($this->n($seed.'base', 120, 480) * $sliceCount, self::CURRENCY)
            ->multipliedBy($multiplier)
            ->multipliedBy($stops > 0 ? '0.85' : '1');

        $base = $adultBase->multipliedBy($pax->adults)
            ->plus($adultBase->percent(75)->multipliedBy($pax->children))
            ->plus($adultBase->percent(10)->multipliedBy($pax->infants));
        $taxes = $base->percent(18)->plus(Money::of(25 * $sliceCount * $pax->seats(), self::CURRENCY));

        return new Fare($base, $taxes);
    }

    /** @return array{checked: string, cabin: string} */
    private function baggage(CabinClass $cabin, string $carrier): array
    {
        $premium = in_array($cabin, [CabinClass::Business, CabinClass::First], true);

        return [
            'checked' => $premium ? '2PC x 32KG' : ($carrier === 'FZ' ? '20KG' : '30KG'),
            'cabin' => '7KG',
        ];
    }

    /** @param array<string, mixed> $booking */
    private function save(array $booking): void
    {
        $this->cache->put($this->key('booking', $booking['id']), $booking, self::TTL_SECONDS);
        $this->cache->put($this->key('ref', $booking['client_reference']), $booking['id'], self::TTL_SECONDS);
    }

    /** @return array<string, mixed> */
    private function load(string $id): array
    {
        $booking = $this->cache->get($this->key('booking', $id));
        if (! $booking) {
            throw ProviderRejectedException::for(self::CODE, "Booking [{$id}] not found at provider", 'BOOKING_NOT_FOUND');
        }

        if (! empty($booking['tickets_on_retrieve'])) {
            $booking['status'] = ProviderBookingStatus::Ticketed->value;
            unset($booking['tickets_on_retrieve']);
            $this->save($booking);
        }
        if (($booking['refund_status'] ?? null) === 'pending' && $this->scenario() !== 'refund_pending') {
            $booking['status'] = ProviderBookingStatus::Refunded->value;
            unset($booking['refund_status']);
            $this->save($booking);
        }

        return $booking;
    }

    /** @param array<string, mixed> $booking */
    private function isExpired(array $booking): bool
    {
        return $booking['status'] === ProviderBookingStatus::Held->value
            && CarbonImmutable::parse($booking['hold_expires_at'])->isPast();
    }

    /** @param array<string, mixed> $booking */
    private function toResult(array $booking): ProviderBookingResult
    {
        $status = $this->isExpired($booking) ? ProviderBookingStatus::Expired : ProviderBookingStatus::from($booking['status']);

        return new ProviderBookingResult(
            providerBookingId: $booking['id'],
            status: $status,
            pnr: $booking['pnr'],
            holdExpiresAt: $booking['hold_expires_at'],
            ticketingDeadline: $booking['hold_expires_at'],
            fare: Fare::of($booking['fare']['base'], $booking['fare']['taxes'], $booking['fare']['currency']),
            tickets: in_array($status, [ProviderBookingStatus::Ticketed, ProviderBookingStatus::Refunded], true) ? $this->tickets($booking) : [],
        );
    }

    /**
     * @param  array<string, mixed>  $booking
     * @return list<TicketData>
     */
    private function tickets(array $booking): array
    {
        return array_map(
            fn (array $t) => new TicketData($t['index'], $t['number'], $t['airline'], now()->toIso8601String()),
            $booking['tickets'],
        );
    }

    /** @param array<string, mixed> $booking */
    private function refundResult(array $booking): RefundResult
    {
        $fare = Fare::of($booking['fare']['base'], $booking['fare']['taxes'], $booking['fare']['currency']);
        // Refundable fares lose 10% of base; non-refundable fares only get taxes back.
        $penalty = $booking['refundable'] ? $fare->base->percent(10) : $fare->base;

        return new RefundResult('completed', $fare->total()->minus($penalty), $penalty, 'MR-'.$booking['id']);
    }

    private function pnr(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $pnr = '';
        for ($i = 0; $i < 6; $i++) {
            $pnr .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $pnr;
    }

    private function key(string $type, string $id): string
    {
        return "mock_provider:{$type}:{$id}";
    }

    /** Deterministic pseudo-random integer in [min, max] derived from a seed string. */
    private function n(string $seed, int $min, int $max): int
    {
        return $min + (hexdec(substr(hash('sha256', $seed), 0, 8)) % ($max - $min + 1));
    }
}
