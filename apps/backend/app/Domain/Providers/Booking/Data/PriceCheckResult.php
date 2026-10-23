<?php

namespace App\Domain\Providers\Booking\Data;

final readonly class PriceCheckResult
{
    public function __construct(
        public bool $available,
        public ?Fare $fare = null,
        public ?string $providerOfferId = null, // some providers issue a new offer id on re-price
        public ?string $expiresAt = null,
        public ?string $message = null,
    ) {}

    public static function unavailable(string $message = 'Offer is no longer available'): self
    {
        return new self(false, message: $message);
    }
}
