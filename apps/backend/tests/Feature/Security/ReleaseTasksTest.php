<?php

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Permissions\PermissionCatalog;
use Illuminate\Support\Facades\Cache;

it('runs the release tasks once per release with --once', function () {
    config(['platform.release' => 'abc123']);

    $this->artisan('platform:release-tasks', ['--once' => true])->assertSuccessful();
    expect(Cache::store('database')->get('platform:release-tasks'))->toBe('abc123');

    $this->artisan('platform:release-tasks', ['--once' => true])
        ->expectsOutputToContain('already applied for abc123')
        ->assertSuccessful();

    config(['platform.release' => 'def456']);
    $this->artisan('platform:release-tasks', ['--once' => true])
        ->doesntExpectOutputToContain('already applied')
        ->assertSuccessful();
    expect(Cache::store('database')->get('platform:release-tasks'))->toBe('def456');
});

it('keeps every system role permission set after re-syncing the catalogue', function () {
    $this->artisan('platform:release-tasks')->assertSuccessful();

    foreach (array_keys(PermissionCatalog::SYSTEM_ROLES) as $name) {
        expect(Role::findByName($name, 'web')->permissions->pluck('name')->sort()->values()->all())
            ->toBe(collect(PermissionCatalog::permissionsForRole($name))->sort()->values()->all());
    }
});
