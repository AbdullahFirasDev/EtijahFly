<?php

namespace App\Domain\Providers\Booking\Data;

use App\Domain\Shared\Money\Money;

final readonly class RefundResult
{
    public function __construct(
        public string $status, // completed | pending | rejected
        public ?Money $refundAmount = null, // amount the provider returns to the platform
        public ?Money $penalty = null,
        public ?string $providerRefundId = null,
        public ?string $message = null,
    ) {}

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
