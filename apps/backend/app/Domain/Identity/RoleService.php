<?php

namespace App\Domain\Identity;

use App\Domain\AuditLog\Auditor;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions\PermissionCatalog;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Role assignment and custom roles. Enforces scope separation: tenant users only receive
 * tenant roles (system or their own tenant's custom roles); platform users only platform roles.
 */
class RoleService
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly PermissionRegistrar $registrar,
    ) {}

    /**
     * Roles the given tenant (or the platform, when null) may assign.
     *
     * @return Collection<int, Role>
     */
    public function assignableRoles(?int $tenantId): Collection
    {
        return $tenantId === null
            ? Role::query()->platform()->orderBy('id')->get()
            : Role::query()->assignableByTenant($tenantId)->orderBy('id')->get();
    }

    /**
     * @param  list<string>  $roleNames
     * @return Collection<int, Role>
     */
    public function resolveAssignable(?int $tenantId, array $roleNames): Collection
    {
        $roles = $this->assignableRoles($tenantId)->whereIn('name', $roleNames);
        if ($roleNames === [] || $roles->count() !== count(array_unique($roleNames))) {
            throw BusinessRuleException::make('INVALID_ROLES', __('errors.invalid_roles'), 422, ['roles' => $roleNames]);
        }

        return $roles->values();
    }

    /** @param list<string> $roleNames */
    public function syncRoles(User $target, array $roleNames, User $actor): User
    {
        $roles = $this->resolveAssignable($target->tenant_id, $roleNames);
        $before = $target->getRoleNames()->all();

        if ($target->tenant_id && in_array('company_owner', $before, true) && ! $roles->contains('name', 'company_owner')) {
            $this->assertNotLastOwner($target);
        }
        if ($target->is($actor) && $roles->pluck('name')->diff($before)->isNotEmpty()) {
            throw BusinessRuleException::make('CANNOT_ELEVATE_SELF', __('errors.cannot_change_own_roles'), 403);
        }

        $target->syncRoles($roles);
        $this->auditor->record('user.roles_changed', $target, ['roles' => $before], ['roles' => $roles->pluck('name')->all()]);

        return $target;
    }

    /** @param list<string> $permissions */
    public function createCustomRole(?int $tenantId, string $displayName, ?string $description, array $permissions): Role
    {
        $scope = $tenantId === null ? PermissionCatalog::SCOPE_PLATFORM : PermissionCatalog::SCOPE_TENANT;
        $this->assertPermissionsInScope($permissions, $scope);

        $slug = Str::slug($displayName) ?: Str::lower(Str::random(6));
        $name = $tenantId === null ? "p:{$slug}" : Role::customName($tenantId, $slug);
        if (Role::query()->where('name', $name)->exists()) {
            $name .= '-'.Str::lower(Str::random(4));
        }

        /** @var Role $role */
        $role = Role::query()->create([
            'name' => $name,
            'guard_name' => 'web',
            'tenant_id' => $tenantId,
            'scope' => $scope,
            'display_name' => $displayName,
            'description' => $description,
            'is_system' => false,
        ]);
        $role->syncPermissions($permissions);
        $this->registrar->forgetCachedPermissions();

        $this->auditor->record('role.created', $role, new: ['name' => $displayName, 'permissions' => $permissions], tenantId: $tenantId);

        return $role;
    }

    /** @param list<string> $permissions */
    public function updateCustomRole(Role $role, string $displayName, ?string $description, array $permissions): Role
    {
        $this->assertCustom($role);
        $this->assertPermissionsInScope($permissions, $role->scope);

        $before = ['name' => $role->display_name, 'permissions' => $role->permissions->pluck('name')->all()];
        $role->forceFill(['display_name' => $displayName, 'description' => $description])->save();
        $role->syncPermissions($permissions);
        $this->registrar->forgetCachedPermissions();

        $this->auditor->record('role.updated', $role, $before, ['name' => $displayName, 'permissions' => $permissions], tenantId: $role->tenant_id);

        return $role;
    }

    public function deleteCustomRole(Role $role): void
    {
        $this->assertCustom($role);
        if ($role->users()->exists()) {
            throw BusinessRuleException::make('ROLE_IN_USE', __('errors.role_in_use'), 409);
        }

        $this->auditor->record('role.deleted', $role, ['name' => $role->display_name], tenantId: $role->tenant_id);
        $role->delete();
        $this->registrar->forgetCachedPermissions();
    }

    public function assertNotLastOwner(User $user): void
    {
        $owners = User::query()->withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->role('company_owner')
            ->count();

        if ($owners <= 1) {
            throw BusinessRuleException::make('LAST_OWNER', __('errors.last_owner'), 409);
        }
    }

    /** @param list<string> $permissions */
    private function assertPermissionsInScope(array $permissions, string $scope): void
    {
        $allowed = PermissionCatalog::forScope($scope);
        $invalid = array_values(array_filter($permissions, fn (string $p) => ! isset($allowed[$p])));
        $known = Permission::query()->whereIn('name', $permissions)->count();

        if ($permissions === [] || $invalid !== [] || $known !== count(array_unique($permissions))) {
            throw BusinessRuleException::make('INVALID_PERMISSIONS', __('errors.invalid_permissions'), 422, ['invalid' => $invalid]);
        }
    }

    private function assertCustom(Role $role): void
    {
        if ($role->is_system) {
            throw BusinessRuleException::make('SYSTEM_ROLE', __('errors.system_role_immutable'), 403);
        }
    }
}
