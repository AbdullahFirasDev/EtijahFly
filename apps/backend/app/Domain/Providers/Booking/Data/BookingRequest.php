<?php

namespace App\Domain\Providers\Booking\Data;

final readonly class BookingRequest
{
    /** @param list<PassengerData> $passengers */
    public function __construct(
        public string $providerOfferId,
        public string $clientReference, // platform booking reference, sent for reconciliation
        public array $passengers,
        public string $contactEmail,
        public ?string $contactPhone,
        public Fare $expectedFare,
    ) {}
}
