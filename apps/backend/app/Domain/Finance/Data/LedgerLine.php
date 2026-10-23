<?php

namespace App\Domain\Finance\Data;

use App\Domain\Finance\Models\LedgerAccount;
use App\Domain\Shared\Money\Money;

final readonly class LedgerLine
{
    public function __construct(
        public LedgerAccount $account,
        public string $direction, // debit | credit
        public Money $amount,
    ) {}

    public static function debit(LedgerAccount $account, Money $amount): self
    {
        return new self($account, 'debit', $amount);
    }

    public static function credit(LedgerAccount $account, Money $amount): self
    {
        return new self($account, 'credit', $amount);
    }
}
