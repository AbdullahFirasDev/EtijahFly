<?php

namespace App\Domain\Providers\Booking\Data;

use App\Domain\Shared\Money\Money;

/** Supplier (net) fare for all passengers of an offer. */
final readonly class Fare
{
    public function __construct(
        public Money $base,
        public Money $taxes,
    ) {}

    public static function of(string $base, string $taxes, string $currency): self
    {
        return new self(Money::of($base, $currency), Money::of($taxes, $currency));
    }

    public function total(): Money
    {
        return $this->base->plus($this->taxes);
    }

    public function currency(): string
    {
        return $this->base->currency;
    }

    /** @return array{currency: string, base: string, taxes: string, total: string} */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency(),
            'base' => $this->base->toDecimal(),
            'taxes' => $this->taxes->toDecimal(),
            'total' => $this->total()->toDecimal(),
        ];
    }
}
