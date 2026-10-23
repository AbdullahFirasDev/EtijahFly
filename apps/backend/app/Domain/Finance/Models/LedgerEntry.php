<?php

namespace App\Domain\Finance\Models;

use App\Domain\Shared\Support\AppendOnly;
use App\Domain\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $ledger_transaction_id
 * @property int $ledger_account_id
 * @property int|null $tenant_id
 * @property string $direction
 * @property string $amount
 * @property string $balance_after
 * @property Carbon $created_at
 * @property-read LedgerTransaction $transaction
 * @property-read LedgerAccount $account
 */
class LedgerEntry extends Model
{
    use AppendOnly, BelongsToTenant;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function assignsCurrentTenant(): bool
    {
        return false;
    }

    /** @return BelongsTo<LedgerTransaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'ledger_transaction_id');
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }
}
