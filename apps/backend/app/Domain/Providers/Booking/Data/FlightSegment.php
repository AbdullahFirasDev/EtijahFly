<?php

namespace App\Domain\Providers\Booking\Data;

final readonly class FlightSegment
{
    public function __construct(
        public string $marketingCarrier,
        public string $flightNumber,
        public string $origin,
        public string $destination,
        public string $departureAt, // ISO-8601 with offset
        public string $arrivalAt,
        public int $durationMinutes,
        public ?string $operatingCarrier = null,
        public ?string $aircraft = null,
        public ?string $bookingClass = null,
        public ?string $departureTerminal = null,
        public ?string $arrivalTerminal = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'marketing_carrier' => $this->marketingCarrier,
            'operating_carrier' => $this->operatingCarrier ?? $this->marketingCarrier,
            'flight_number' => $this->flightNumber,
            'origin' => $this->origin,
            'destination' => $this->destination,
            'departure_at' => $this->departureAt,
            'arrival_at' => $this->arrivalAt,
            'duration_minutes' => $this->durationMinutes,
            'aircraft' => $this->aircraft,
            'booking_class' => $this->bookingClass,
            'departure_terminal' => $this->departureTerminal,
            'arrival_terminal' => $this->arrivalTerminal,
        ];
    }
}
