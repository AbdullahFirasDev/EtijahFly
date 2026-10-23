<?php

use App\Domain\Identity\Models\Role;
use App\Domain\Ticketing\Jobs\GenerateBookingDocuments;
use App\Domain\Ticketing\Jobs\GenerateInvoiceDocument;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake([GenerateBookingDocuments::class, GenerateInvoiceDocument::class]));

it('keeps partner and admin portals strictly separate', function () {
    actingAsPartner(agencyUser(agency(), 'company_owner'));
    $this->getJson('/api/v1/admin/tenants')->assertForbidden()->assertJsonPath('code', 'WRONG_PORTAL');

    actingAsAdmin(platformUser('super_admin'));
    $this->getJson('/api/v1/partner/bookings')->assertForbidden()->assertJsonPath('code', 'WRONG_PORTAL');
});

it('rejects a partner user holding an admin-portal token', function () {
    actingAsAdmin(agencyUser(agency(), 'company_owner'));

    $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
});

it('requires authentication everywhere except public endpoints', function () {
    $this->getJson('/api/v1/partner/dashboard')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
    $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
    $this->getJson('/api/v1/health')->assertOk();
});

it('enforces role permissions inside an agency', function (string $role, string $method, string $uri, int $expected) {
    $tenant = agency();
    actingAsPartner(agencyUser($tenant, $role));

    $status = $this->json($method, $uri, [])->status();
    expect($status)->toBe($expected);
})->with([
    'agent cannot read finance' => ['booking_agent', 'GET', '/api/v1/partner/finance/account', 403],
    'agent cannot see statements' => ['booking_agent', 'GET', '/api/v1/partner/finance/statement', 403],
    'agent cannot manage team' => ['booking_agent', 'POST', '/api/v1/partner/team/roles', 403],
    'agent cannot change branding' => ['booking_agent', 'PUT', '/api/v1/partner/settings/branding', 403],
    'agent can search (validation)' => ['booking_agent', 'POST', '/api/v1/partner/flights/search', 422],
    'accountant cannot search' => ['accountant', 'POST', '/api/v1/partner/flights/search', 403],
    'accountant cannot manage travelers' => ['accountant', 'GET', '/api/v1/partner/travelers', 403],
    'accountant reads finance' => ['accountant', 'GET', '/api/v1/partner/finance/account', 200],
    'accountant reads reports' => ['accountant', 'GET', '/api/v1/partner/reports/summary', 200],
    'owner manages roles (validation)' => ['company_owner', 'POST', '/api/v1/partner/team/roles', 422],
]);

it('limits agents without bookings.view_all to their own bookings', function () {
    $tenant = agency('USD', '5000.00');
    $agent1 = actingAsPartner(agencyUser($tenant, 'booking_agent'));
    $booking = createBooking(firstOffer())->assertCreated()->json('data');

    actingAsPartner(agencyUser($tenant, 'booking_agent'));
    $this->getJson("/api/v1/partner/bookings/{$booking['id']}")->assertForbidden();
    expect($this->getJson('/api/v1/partner/bookings')->json('data'))->toBeEmpty();

    actingAsPartner(agencyUser($tenant, 'accountant')); // has bookings.view_all
    $this->getJson("/api/v1/partner/bookings/{$booking['id']}")->assertOk();
    $this->withHeader('Idempotency-Key', 'accountant-cannot-issue')->postJson("/api/v1/partner/bookings/{$booking['id']}/issue")->assertForbidden();
});

it('supports custom company roles limited to tenant permissions', function () {
    $tenant = agency();
    actingAsPartner(agencyUser($tenant, 'company_owner'));

    $this->postJson('/api/v1/partner/team/roles', ['name' => 'Escalation', 'permissions' => ['platform.tenants.manage']])->assertStatus(422);

    $role = $this->postJson('/api/v1/partner/team/roles', ['name' => 'Ticket desk', 'permissions' => ['bookings.view_all', 'bookings.issue', 'documents.view']])
        ->assertCreated()->json('data');
    expect($role['name'])->toBe("t{$tenant->id}:ticket-desk");

    $member = agencyUser($tenant, 'booking_agent');
    $this->putJson("/api/v1/partner/team/users/{$member->id}/roles", ['roles' => [$role['name']]])->assertOk();

    actingAsPartner($member->fresh());
    $this->getJson('/api/v1/partner/bookings')->assertOk();
    $this->postJson('/api/v1/partner/flights/search', searchPayload())->assertForbidden();

    // another agency can neither see nor assign this role
    $other = agency();
    actingAsPartner(agencyUser($other, 'company_owner'));
    expect(collect($this->getJson('/api/v1/partner/team/roles')->json('data'))->pluck('name'))->not->toContain($role['name']);
    $target = agencyUser($other, 'booking_agent');
    $this->putJson("/api/v1/partner/team/users/{$target->id}/roles", ['roles' => [$role['name']]])->assertStatus(422);
    $this->deleteJson("/api/v1/partner/team/roles/{$role['id']}")->assertNotFound();
});

it('protects the last company owner and prevents self-escalation', function () {
    $tenant = agency();
    $owner = actingAsPartner(agencyUser($tenant, 'company_owner'));

    $this->putJson("/api/v1/partner/team/users/{$owner->id}/status", ['status' => 'disabled'])->assertForbidden();

    $agent = agencyUser($tenant, 'booking_agent');
    actingAsPartner($agent);
    $agent->givePermissionTo('team.manage'); // even with team.manage, cannot elevate self
    $this->putJson("/api/v1/partner/team/users/{$agent->id}/roles", ['roles' => ['company_owner']])->assertForbidden();
});

it('disabling a user ends their sessions immediately', function () {
    $tenant = agency();
    $agent = agencyUser($tenant, 'booking_agent');
    $token = $agent->createToken('t', ['portal:partner'])->plainTextToken;

    actingAsPartner(agencyUser($tenant, 'company_owner'));
    $this->putJson("/api/v1/partner/team/users/{$agent->id}/status", ['status' => 'disabled'])->assertOk()->assertJsonPath('data.status', 'disabled');

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('restricts admin endpoints by platform permission', function (string $role, string $method, string $uri, int $expected) {
    actingAsAdmin(platformUser($role));

    expect($this->json($method, $uri)->status())->toBe($expected);
})->with([
    'staff views tenants' => ['platform_staff', 'GET', '/api/v1/admin/tenants', 200],
    'staff cannot create tenants' => ['platform_staff', 'POST', '/api/v1/admin/tenants', 403],
    'staff cannot manage pricing' => ['platform_staff', 'POST', '/api/v1/admin/pricing-rules', 403],
    'staff cannot manage providers' => ['platform_staff', 'PATCH', '/api/v1/admin/providers/mock', 403],
    'staff reads audit log' => ['platform_staff', 'GET', '/api/v1/admin/audit-logs', 200],
    'finance cannot manage users' => ['platform_finance', 'GET', '/api/v1/admin/users', 403],
    'finance views payments' => ['platform_finance', 'GET', '/api/v1/admin/finance/payments', 200],
    'super admin manages users' => ['super_admin', 'GET', '/api/v1/admin/users', 200],
]);

it('never assigns platform roles to tenant roles at the data level', function () {
    expect(Role::query()->where('scope', 'tenant')->whereHas('permissions', fn ($q) => $q->where('scope', 'platform'))->exists())->toBeFalse();
});
