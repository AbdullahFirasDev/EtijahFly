<?php

namespace App\Domain\Commissions;

final readonly class PricingContext
{
    public function __construct(
        public ?int $tenantId,
        public ?string $airlineCode,
        public string $cabin,
        public string $tripType,
        public string $origin,
        public string $destination,
        public string $providerCode,
    ) {}
}
