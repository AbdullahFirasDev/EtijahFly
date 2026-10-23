<?php

namespace App\Domain\Providers\Booking\Data;

use App\Domain\FlightSearch\Enums\CabinClass;

final readonly class FlightOfferData
{
    /**
     * @param  list<FlightSlice>  $slices
     * @param  array{checked?: string|null, cabin?: string|null}  $baggage  human readable allowance per passenger
     * @param  array<string, mixed>  $fareRules
     */
    public function __construct(
        public string $providerCode,
        public string $providerOfferId,
        public array $slices,
        public Fare $fare,
        public CabinClass $cabin,
        public ?string $validatingAirline,
        public array $baggage,
        public bool $refundable,
        public array $fareRules,
        public string $expiresAt, // ISO-8601
        public bool $isTest,
        public ?int $seatsRemaining = null,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function itinerary(): array
    {
        return array_map(fn (FlightSlice $s) => $s->toArray(), $this->slices);
    }
}
