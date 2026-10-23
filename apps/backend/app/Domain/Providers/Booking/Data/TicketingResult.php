<?php

namespace App\Domain\Providers\Booking\Data;

final readonly class TicketingResult
{
    /** @param list<TicketData> $tickets */
    public function __construct(
        public bool $issued,
        public array $tickets = [],
        public bool $pending = false, // accepted by provider, tickets will follow asynchronously
        public ?string $message = null,
    ) {}
}
