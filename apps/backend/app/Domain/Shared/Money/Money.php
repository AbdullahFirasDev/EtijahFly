<?php

namespace App\Domain\Shared\Money;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Immutable monetary amount with exact decimal arithmetic (never floats).
 * All platform amounts use 2 decimal places, including IQD.
 */
final class Money implements JsonSerializable, Stringable
{
    public const SCALE = 2;

    private function __construct(
        public readonly BigDecimal $amount,
        public readonly string $currency,
    ) {}

    public static function of(BigDecimal|string|int $amount, string $currency): self
    {
        if (is_string($amount) && ! is_numeric($amount)) {
            throw new InvalidArgumentException("Invalid money amount [{$amount}]");
        }

        return new self(
            BigDecimal::of($amount)->toScale(self::SCALE, RoundingMode::HalfUp),
            strtoupper($currency),
        );
    }

    public static function zero(string $currency): self
    {
        return self::of(0, $currency);
    }

    public function plus(Money $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount->plus($other->amount), $this->currency);
    }

    public function minus(Money $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount->minus($other->amount), $this->currency);
    }

    public function multipliedBy(BigDecimal|string|int $factor): self
    {
        return self::of($this->amount->multipliedBy($factor), $this->currency);
    }

    /** e.g. percent('2.5') = 2.5% of this amount, rounded half-up. */
    public function percent(BigDecimal|string|int $percent): self
    {
        return self::of(
            $this->amount->multipliedBy($percent)->dividedBy(100, 8, RoundingMode::HalfUp),
            $this->currency,
        );
    }

    public function negated(): self
    {
        return new self($this->amount->negated(), $this->currency);
    }

    public function abs(): self
    {
        return new self($this->amount->abs(), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isPositive(): bool
    {
        return $this->amount->isPositive();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function compareTo(Money $other): int
    {
        $this->assertSameCurrency($other);

        return $this->amount->compareTo($other->amount);
    }

    public function greaterThan(Money $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function lessThan(Money $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function equals(Money $other): bool
    {
        return $this->currency === $other->currency && $this->amount->isEqualTo($other->amount);
    }

    public function max(Money $other): self
    {
        return $this->compareTo($other) >= 0 ? $this : $other;
    }

    public function min(Money $other): self
    {
        return $this->compareTo($other) <= 0 ? $this : $other;
    }

    /** Decimal string suitable for decimal DB columns, e.g. "1250.00". */
    public function toDecimal(): string
    {
        return (string) $this->amount;
    }

    public function __toString(): string
    {
        return $this->toDecimal().' '.$this->currency;
    }

    /** @return array{amount: string, currency: string} */
    public function jsonSerialize(): array
    {
        return ['amount' => $this->toDecimal(), 'currency' => $this->currency];
    }

    private function assertSameCurrency(Money $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->currency}");
        }
    }
}
