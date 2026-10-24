<?php

// Role::users() resolves the user model from the current guard, and auth:sanctum makes `sanctum`
// the current guard during API requests: these listings count role members under that guard.

it('lists platform roles with their user counts', function () {
    actingAsAdmin(platformUser('super_admin'));

    $this->getJson('/api/v1/admin/roles')
        ->assertOk()
        ->assertJsonFragment(['name' => 'super_admin', 'users_count' => 1]);
});

it('lists the roles assignable in a company with their user counts', function () {
    $tenant = agency();
    actingAsPartner(agencyUser($tenant, 'company_owner'));
    agencyUser($tenant, 'booking_agent');

    $this->getJson('/api/v1/partner/team/roles')
        ->assertOk()
        ->assertJsonFragment(['name' => 'company_owner', 'users_count' => 1]);
});
