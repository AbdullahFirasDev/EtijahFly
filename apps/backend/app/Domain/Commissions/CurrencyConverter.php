<?php

namespace App\Domain\Commissions;

use App\Domain\Commissions\Models\ExchangeRate;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Shared\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;

class CurrencyConverter
{
    /**
     * Rate such that 1 unit of $from = rate units of $to, effective at $at.
     */
    public function rate(string $from, string $to, ?CarbonInterface $at = null): BigDecimal
    {
        if ($from === $to) {
            return BigDecimal::one();
        }

        $at ??= now();

        $direct = ExchangeRate::query()
            ->where('base_currency', $from)->where('quote_currency', $to)
            ->where('effective_at', '<=', $at)
            ->orderByDesc('effective_at')->orderByDesc('id')
            ->first();
        if ($direct) {
            return BigDecimal::of($direct->rate);
        }

        $inverse = ExchangeRate::query()
            ->where('base_currency', $to)->where('quote_currency', $from)
            ->where('effective_at', '<=', $at)
            ->orderByDesc('effective_at')->orderByDesc('id')
            ->first();
        if ($inverse) {
            return BigDecimal::one()->dividedBy($inverse->rate, 10, RoundingMode::HalfUp);
        }

        throw BusinessRuleException::make(
            'EXCHANGE_RATE_MISSING',
            __('errors.exchange_rate_missing', ['from' => $from, 'to' => $to]),
            422,
            ['from' => $from, 'to' => $to],
        );
    }

    public function convert(Money $money, string $to, ?BigDecimal $rate = null): Money
    {
        if ($money->currency === $to) {
            return $money;
        }

        $rate ??= $this->rate($money->currency, $to);

        return Money::of($money->amount->multipliedBy($rate), $to);
    }
}
