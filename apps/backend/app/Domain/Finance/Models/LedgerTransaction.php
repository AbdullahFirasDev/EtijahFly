<?php

namespace App\Domain\Finance\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Support\AppendOnly;
use App\Domain\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $tenant_id
 * @property string $type
 * @property string $currency
 * @property string $amount
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property int|null $reverses_transaction_id
 * @property string $description
 * @property string|null $fx_rate
 * @property string|null $fx_source_currency
 * @property array<string, mixed>|null $metadata
 * @property string|null $idempotency_key
 * @property int|null $created_by
 * @property Carbon $created_at
 */
class LedgerTransaction extends Model
{
    use AppendOnly, BelongsToTenant;

    public const UPDATED_AT = null;

    public const TYPE_BOOKING_CHARGE = 'booking_charge';

    public const TYPE_REFUND = 'refund';

    public const TYPE_PAYMENT = 'payment';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_REVERSAL = 'reversal';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fx_rate' => 'decimal:6',
            'metadata' => 'array',
            'created_at' => 'datetime',
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

    /** @return MorphTo<Model, $this> */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<LedgerTransaction, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_transaction_id');
    }

    /** @return HasOne<LedgerTransaction, $this> */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_transaction_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withoutGlobalScopes();
    }
}
