<?php

namespace App\Domain\Commissions\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only history: 1 base_currency = rate quote_currency, from effective_at.
 *
 * @property int $id
 * @property string $base_currency
 * @property string $quote_currency
 * @property string $rate
 * @property Carbon $effective_at
 * @property string $source
 * @property int|null $created_by
 * @property Carbon $created_at
 */
class ExchangeRate extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['base_currency', 'quote_currency', 'rate', 'effective_at', 'source', 'created_by'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:6', 'effective_at' => 'datetime', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withoutGlobalScopes();
    }
}
