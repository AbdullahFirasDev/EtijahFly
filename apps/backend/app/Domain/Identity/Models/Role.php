<?php

namespace App\Domain\Identity\Models;

use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * @property int|null $tenant_id
 * @property string $scope
 * @property string|null $display_name
 * @property string|null $description
 * @property bool $is_system
 */
class Role extends SpatieRole
{
    protected $casts = ['is_system' => 'boolean'];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Roles a tenant may assign: shared system tenant roles plus its own custom roles.
     *
     * @param  Builder<Role>  $query
     */
    public function scopeAssignableByTenant(Builder $query, int $tenantId): void
    {
        $query->where('scope', 'tenant')
            ->where(fn (Builder $q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId));
    }

    /** @param Builder<Role> $query */
    public function scopePlatform(Builder $query): void
    {
        $query->where('scope', 'platform')->whereNull('tenant_id');
    }

    public static function customName(int $tenantId, string $slug): string
    {
        return "t{$tenantId}:{$slug}";
    }

    public function label(): string
    {
        return $this->display_name ?: __('roles.'.$this->name);
    }
}
