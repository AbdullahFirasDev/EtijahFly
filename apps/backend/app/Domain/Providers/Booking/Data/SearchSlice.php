<?php

namespace App\Domain\Providers\Booking\Data;

final readonly class SearchSlice
{
    public function __construct(
        public string $origin,
        public string $destination,
        public string $date, // Y-m-d, local to origin
    ) {}

    /** @return array{origin: string, destination: string, date: string} */
    public function toArray(): array
    {
        return ['origin' => $this->origin, 'destination' => $this->destination, 'date' => $this->date];
    }
}
