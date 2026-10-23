<?php

namespace App\Domain\Shared\Tenancy;

use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Row-level tenant isolation for Eloquent models with a `tenant_id` column:
 *  - reads are filtered to the current tenant (TenantScope) whenever a context is set;
 *  - new rows inherit the current tenant;
 *  - writing a row that belongs to another tenant while a context is set is refused.
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (self $model) {
            $context = app(TenantContext::class);
            if ($context->has() && $model->getAttribute('tenant_id') === null && $model->assignsCurrentTenant()) {
                $model->setAttribute('tenant_id', $context->id());
            }
        });

        static::saving(function (Model $model) {
            $context = app(TenantContext::class);
            if ($context->has() && $model->getAttribute('tenant_id') !== null
                && (int) $model->getAttribute('tenant_id') !== $context->id()) {
                throw new LogicException('Refusing to write a record that belongs to another tenant.');
            }
        });
    }

    /**
     * Whether new rows without tenant_id inherit the current tenant. Models that legitimately
     * hold platform-level rows (tenant_id NULL), such as ledger and audit tables, return false.
     */
    public function assignsCurrentTenant(): bool
    {
        return true;
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
