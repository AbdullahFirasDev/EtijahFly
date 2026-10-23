<?php

namespace App\Domain\Bookings\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Support\AppendOnly;
use App\Domain\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable booking timeline entry.
 *
 * @property int $id
 * @property int $booking_id
 * @property int $tenant_id
 * @property string $type
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string $message
 * @property array<string, mixed>|null $data
 * @property int|null $actor_id
 * @property string $actor_type
 * @property Carbon $created_at
 */
class BookingEvent extends Model
{
    use AppendOnly, BelongsToTenant;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->withoutGlobalScopes();
    }
}
