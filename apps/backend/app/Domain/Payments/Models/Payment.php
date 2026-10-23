<?php

namespace App\Domain\Payments\Models;

use App\Domain\Finance\Models\LedgerAccount;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Agency top-up / settlement. Credited to the wallet only after platform approval.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $ledger_account_id
 * @property string $reference
 * @property string $amount
 * @property string $currency
 * @property string $method
 * @property string $gateway
 * @property string|null $external_reference
 * @property Carbon $paid_at
 * @property string|null $proof_path
 * @property string $status pending | approved | rejected
 * @property string|null $notes
 * @property int|null $submitted_by
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_notes
 * @property int|null $ledger_transaction_id
 * @property string|null $idempotency_key
 */
class Payment extends Model
{
    use BelongsToTenant;

    public const METHODS = ['bank_transfer', 'cash', 'cheque'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by')->withoutGlobalScopes();
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withoutGlobalScopes();
    }
}
