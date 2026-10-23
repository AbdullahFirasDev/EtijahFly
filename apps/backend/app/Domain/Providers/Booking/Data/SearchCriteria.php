<?php

namespace App\Domain\Providers\Booking\Data;

use App\Domain\FlightSearch\Enums\CabinClass;
use App\Domain\FlightSearch\Enums\TripType;

final readonly class SearchCriteria
{
    /**
     * @param  list<SearchSlice>  $slices  one per direction (2 for round trip, N for multi-city)
     */
    public function __construct(
        public TripType $tripType,
        public array $slices,
        public PassengerCounts $passengers,
        public CabinClass $cabin,
        public ?int $maxStops = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'trip_type' => $this->tripType->value,
            'slices' => array_map(fn (SearchSlice $s) => $s->toArray(), $this->slices),
            'passengers' => $this->passengers->toArray(),
            'cabin' => $this->cabin->value,
            'max_stops' => $this->maxStops,
        ];
    }
}
