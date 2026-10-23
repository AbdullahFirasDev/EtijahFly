<?php

namespace App\Domain\Providers\Booking\Data;

/** One direction of travel (outbound, return, or one leg of a multi-city trip). */
final readonly class FlightSlice
{
    /** @param list<FlightSegment> $segments */
    public function __construct(
        public array $segments,
        public int $durationMinutes,
    ) {}

    public function stops(): int
    {
        return max(0, count($this->segments) - 1);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $first = $this->segments[0];
        $last = $this->segments[count($this->segments) - 1];

        return [
            'origin' => $first->origin,
            'destination' => $last->destination,
            'departure_at' => $first->departureAt,
            'arrival_at' => $last->arrivalAt,
            'duration_minutes' => $this->durationMinutes,
            'stops' => $this->stops(),
            'segments' => array_map(fn (FlightSegment $s) => $s->toArray(), $this->segments),
        ];
    }
}
