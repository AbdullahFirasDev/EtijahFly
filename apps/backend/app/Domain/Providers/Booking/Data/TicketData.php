<?php

namespace App\Domain\Providers\Booking\Data;

final readonly class TicketData
{
    public function __construct(
        public int $passengerIndex, // position in BookingRequest::$passengers
        public string $ticketNumber,
        public ?string $airlineCode = null,
        public ?string $issuedAt = null,
    ) {}
}
