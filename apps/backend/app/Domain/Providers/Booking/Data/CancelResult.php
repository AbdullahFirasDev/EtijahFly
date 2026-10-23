<?php

namespace App\Domain\Providers\Booking\Data;

final readonly class CancelResult
{
    public function __construct(
        public bool $cancelled,
        public ?string $message = null,
    ) {}
}
