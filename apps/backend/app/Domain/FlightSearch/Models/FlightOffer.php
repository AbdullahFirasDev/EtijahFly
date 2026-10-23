<?php

namespace App\Domain\FlightSearch\Models;

use App\Domain\Providers\Booking\Data\Fare;
use App\Domain\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Snapshot of a provider offer as priced for one agency. ULID keys so ids are not guessable.
 *
 * @property string $id
 * @property int $tenant_id
 * @property int $flight_search_id
 * @property string $provider_code
 * @property string $provider_offer_id
 * @property bool $is_test
 * @property string|null $validating_airline
 * @property list<array<string, mixed>> $itinerary
 * @property array<string, mixed>|null $baggage
 * @property array<string, mixed>|null $fare_rules
 * @property bool $refundable
 * @property string $cabin
 * @property array{adults: int, children: int, infants: int} $passengers
 * @property string $currency
 * @property string $supplier_base
 * @property string $supplier_taxes
 * @property string $supplier_total
 * @property array<string, mixed> $pricing
 * @property string $sale_total
 * @property Carbon $expires_at
 * @property Carbon $created_at
 * @property-read FlightSearch $search
 */
class FlightOffer extends Model
{
    use BelongsToTenant, HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_test' => 'boolean',
            'refundable' => 'boolean',
            'itinerary' => 'array',
            'baggage' => 'array',
            'fare_rules' => 'array',
            'passengers' => 'array',
            'pricing' => 'array',
            'supplier_base' => 'decimal:2',
            'supplier_taxes' => 'decimal:2',
            'supplier_total' => 'decimal:2',
            'sale_total' => 'decimal:2',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<FlightSearch, $this> */
    public function search(): BelongsTo
    {
        return $this->belongsTo(FlightSearch::class, 'flight_search_id');
    }

    public function fare(): Fare
    {
        return Fare::of((string) $this->supplier_base, (string) $this->supplier_taxes, $this->currency);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
