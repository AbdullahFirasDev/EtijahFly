<?php

namespace App\Domain\Finance\Models;

use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Tenancy\BelongsToTenant;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $tenant_id
 * @property string $code
 * @property string $name
 * @property string $normal_side debit | credit
 * @property string $currency
 * @property string $balance
 * @property string $credit_limit
 * @property string|null $low_balance_threshold
 * @property string $status active | frozen
 * @property-read Tenant|null $tenant
 */
class LedgerAccount extends Model
{
    use BelongsToTenant;

    /** Agency prepaid funds / credit. Liability of the platform (credit-normal). */
    public const AGENCY_WALLET = 'AGENCY_WALLET';

    /** Amounts owed to booking providers. */
    public const SUPPLIER_PAYABLE = 'SUPPLIER_PAYABLE';

    public const MARKUP_REVENUE = 'MARKUP_REVENUE';

    public const COMMISSION_EXPENSE = 'COMMISSION_EXPENSE';

    public const REFUND_FEE_REVENUE = 'REFUND_FEE_REVENUE';

    /** Bank/cash received from agencies. */
    public const CASH_BANK = 'CASH_BANK';

    /** Counterpart of approved manual adjustments. */
    public const ADJUSTMENTS = 'ADJUSTMENTS';

    /** @var array<string, array{name: string, normal_side: string}> */
    public const SYSTEM_ACCOUNTS = [
        self::SUPPLIER_PAYABLE => ['name' => 'Supplier payable', 'normal_side' => 'credit'],
        self::MARKUP_REVENUE => ['name' => 'Markup revenue', 'normal_side' => 'credit'],
        self::COMMISSION_EXPENSE => ['name' => 'Agency commission expense', 'normal_side' => 'debit'],
        self::REFUND_FEE_REVENUE => ['name' => 'Refund fee revenue', 'normal_side' => 'credit'],
        self::CASH_BANK => ['name' => 'Cash and bank', 'normal_side' => 'debit'],
        self::ADJUSTMENTS => ['name' => 'Manual adjustments', 'normal_side' => 'debit'],
    ];

    protected $fillable = ['tenant_id', 'code', 'name', 'normal_side', 'currency', 'balance', 'credit_limit', 'low_balance_threshold', 'status'];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'low_balance_threshold' => 'decimal:2',
        ];
    }

    public function assignsCurrentTenant(): bool
    {
        return false;
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function balanceMoney(): Money
    {
        return Money::of((string) $this->balance, $this->currency);
    }

    public function creditLimitMoney(): Money
    {
        return Money::of((string) $this->credit_limit, $this->currency);
    }

    /** Funds the agency can spend: own balance plus granted credit. */
    public function available(): Money
    {
        return $this->balanceMoney()->plus($this->creditLimitMoney());
    }

    /** Amount the agency owes the platform (negative balance), else zero. */
    public function outstanding(): Money
    {
        return $this->balanceMoney()->isNegative() ? $this->balanceMoney()->abs() : Money::zero($this->currency);
    }

    public function isFrozen(): bool
    {
        return $this->status === 'frozen';
    }

    /** Signed effect of an entry on this account's balance. */
    public function effectOf(string $direction, Money $amount): Money
    {
        return $direction === $this->normal_side ? $amount : $amount->negated();
    }
}
