<?php

namespace Database\Seeders;

use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Permissions\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/** Idempotent: safe to run on every deploy to sync the permission catalogue. */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([PermissionCatalog::SCOPE_TENANT, PermissionCatalog::SCOPE_PLATFORM] as $scope) {
            foreach (PermissionCatalog::forScope($scope) as $name => $group) {
                Permission::query()->updateOrCreate(
                    ['name' => $name, 'guard_name' => 'web'],
                    ['scope' => $scope, 'group' => $group],
                );
            }
        }

        foreach (PermissionCatalog::SYSTEM_ROLES as $name => $definition) {
            /** @var Role $role */
            $role = Role::query()->updateOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['scope' => $definition['scope'], 'tenant_id' => null, 'is_system' => true],
            );
            $role->syncPermissions(PermissionCatalog::permissionsForRole($name));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
