<?php

namespace App\Domain\Commissions\Models;

use App\Domain\Commissions\PricingContext;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Platform-managed pricing rule. NOT tenant-scoped: agencies never read rules directly,
 * only the resulting prices. tenant_id NULL = applies to every agency.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $name
 * @property string $type markup | commission
 * @property string $calculation percent | fixed
 * @property string $value
 * @property string|null $currency
 * @property string $basis supplier_total | base_fare
 * @property string|null $min_amount
 * @property string|null $max_amount
 * @property list<string>|null $airline_codes
 * @property string|null $cabin
 * @property string|null $trip_type
 * @property string|null $origin
 * @property string|null $destination
 * @property string|null $provider_code
 * @property int $priority
 * @property bool $is_active
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 */
class PricingRule extends Model
{
    use SoftDeletes;

    public const TYPE_MARKUP = 'markup';

    public const TYPE_COMMISSION = 'commission';

    public const CALC_PERCENT = 'percent';

    public const CALC_FIXED = 'fixed';

    public const BASIS_SUPPLIER_TOTAL = 'supplier_total';

    public const BASIS_BASE_FARE = 'base_fare';

    protected $fillable = [
        'tenant_id', 'name', 'type', 'calculation', 'value', 'currency', 'basis', 'min_amount', 'max_amount',
        'airline_codes', 'cabin', 'trip_type', 'origin', 'destination', 'provider_code', 'priority',
        'is_active', 'valid_from', 'valid_to', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'airline_codes' => 'array',
            'is_active' => 'boolean',
            'priority' => 'integer',
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @param Builder<PricingRule> $query */
    public function scopeEffective(Builder $query, string $type, ?int $tenantId, Carbon $at): void
    {
        $query->where('type', $type)
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('tenant_id')->when($tenantId, fn ($q) => $q->orWhere('tenant_id', $tenantId)))
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $at))
            ->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $at));
    }

    public function matches(PricingContext $context): bool
    {
        return ($this->airline_codes === null || $this->airline_codes === [] || in_array($context->airlineCode, $this->airline_codes, true))
            && ($this->cabin === null || $this->cabin === $context->cabin)
            && ($this->trip_type === null || $this->trip_type === $context->tripType)
            && ($this->origin === null || $this->origin === $context->origin)
            && ($this->destination === null || $this->destination === $context->destination)
            && ($this->provider_code === null || $this->provider_code === $context->providerCode);
    }

    /** Higher = more specific; used to rank matching rules before priority. */
    public function specificity(): int
    {
        return ($this->tenant_id ? 100 : 0)
            + ($this->airline_codes ? 10 : 0)
            + ($this->origin ? 5 : 0) + ($this->destination ? 5 : 0)
            + ($this->cabin ? 3 : 0) + ($this->trip_type ? 2 : 0) + ($this->provider_code ? 1 : 0);
    }

    /** @return array{id: int, name: string, calculation: string, value: string, currency: string|null, basis: string} */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'calculation' => $this->calculation,
            'value' => (string) $this->value,
            'currency' => $this->currency,
            'basis' => $this->basis,
        ];
    }
}
