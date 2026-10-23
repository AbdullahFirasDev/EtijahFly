<?php

namespace App\Domain\Providers\Booking\Data;

final readonly class ProviderBookingResult
{
    /** @param list<TicketData> $tickets */
    public function __construct(
        public string $providerBookingId,
        public ProviderBookingStatus $status,
        public ?string $pnr = null,
        public ?string $holdExpiresAt = null,
        public ?string $ticketingDeadline = null,
        public ?Fare $fare = null,
        public array $tickets = [],
    ) {}
}
