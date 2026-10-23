<?php

namespace App\Domain\FlightSearch\Models;

use App\Domain\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $user_id
 * @property string $trip_type
 * @property list<array{origin: string, destination: string, date: string}> $slices
 * @property array{adults: int, children: int, infants: int} $passengers
 * @property string $cabin
 * @property string $status
 * @property int $result_count
 * @property array<string, mixed>|null $provider_errors
 * @property int|null $duration_ms
 * @property Carbon $created_at
 */
class FlightSearch extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'slices' => 'array',
            'passengers' => 'array',
            'provider_errors' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return HasMany<FlightOffer, $this> */
    public function offers(): HasMany
    {
        return $this->hasMany(FlightOffer::class);
    }
}
