<?php

use App\Domain\AuditLog\Models\AuditLog;
use App\Domain\Commissions\CurrencyConverter;
use App\Domain\Commissions\Models\PricingRule;
use App\Domain\Identity\Models\Invitation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Ticketing\Jobs\GenerateBookingDocuments;
use App\Domain\Ticketing\Jobs\GenerateInvoiceDocument;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    Notification::fake();
    Queue::fake([GenerateBookingDocuments::class, GenerateInvoiceDocument::class]);
    $this->admin = actingAsAdmin(platformUser('super_admin'));
});

it('onboards an agency with wallet, branding and an owner invitation', function () {
    $response = $this->postJson('/api/v1/admin/tenants', [
        'name' => 'Ur Travel', 'default_currency' => 'IQD', 'contact_email' => 'info@ur.example',
        'owner_email' => 'owner@ur.example', 'owner_name' => 'Owner', 'credit_limit' => '2000000',
    ])->assertCreated()->assertJsonPath('tenant.slug', 'ur-travel')->assertJsonPath('tenant.accounts.0.credit_limit', '2000000.00');

    $tenant = Tenant::query()->findOrFail($response->json('tenant.id'));
    expect($tenant->branding->display_name)->toBe('Ur Travel')
        ->and(Invitation::query()->sole()->roles)->toBe(['company_owner'])
        ->and(AuditLog::query()->where('action', 'tenant.created')->exists())->toBeTrue();
});

it('suspends and reactivates an agency, ending its sessions', function () {
    $tenant = agency();
    $token = agencyUser($tenant)->createToken('t', ['portal:partner'])->plainTextToken;

    $this->postJson("/api/v1/admin/tenants/{$tenant->id}/suspend", ['reason' => 'Unpaid balance'])->assertOk()->assertJsonPath('data.status', 'suspended');
    expect(PersonalAccessToken::query()->count())->toBe(0);

    $this->postJson("/api/v1/admin/tenants/{$tenant->id}/activate")->assertOk()->assertJsonPath('data.status', 'active');
});

it('shows internal pricing to platform staff but not to agencies', function () {
    PricingRule::query()->create(['name' => 'm', 'type' => 'markup', 'calculation' => 'percent', 'value' => '5', 'basis' => 'supplier_total']);
    $tenant = agency('USD', '5000');
    actingAsPartner(agencyUser($tenant, 'company_owner'));
    $booking = createBooking(firstOffer())->json('data');
    expect($booking)->not->toHaveKey('internal');

    actingAsAdmin($this->admin);
    $this->getJson("/api/v1/admin/bookings/{$booking['id']}")->assertOk()
        ->assertJsonStructure(['data' => ['internal' => ['supplier_total', 'markup', 'platform_profit', 'pricing_rules']]])
        ->assertJsonPath('data.internal.tenant.id', $tenant->id);
});

it('reports sales, profit and receivables by currency', function () {
    PricingRule::query()->create(['name' => 'm', 'type' => 'markup', 'calculation' => 'percent', 'value' => '5', 'basis' => 'supplier_total']);
    $tenant = agency('USD', '5000');
    actingAsPartner(agencyUser($tenant, 'company_owner'));
    $b = createBooking(firstOffer())->json('data');
    issueBooking($b['id'])->assertOk();

    actingAsAdmin($this->admin);
    $summary = $this->getJson('/api/v1/admin/reports/summary')->assertOk()->json();
    expect($summary['sales_by_currency'][0])->toMatchArray(['currency' => 'USD', 'bookings' => 1, 'sales' => $b['price']['total']])
        ->and((float) $summary['sales_by_currency'][0]['profit'])->toBeGreaterThan(0);

    $receivables = collect($this->getJson('/api/v1/admin/reports/receivables')->assertOk()->json('data'));
    expect($receivables->firstWhere('tenant_id', $tenant->id)['balance'])->toBe(wallet($tenant)->balance);

    // agency report hides internal figures
    actingAsPartner(agencyUser($tenant, 'accountant'));
    $partner = $this->getJson('/api/v1/partner/reports/summary')->assertOk()->json('sales_by_currency.0');
    expect($partner)->not->toHaveKey('profit')->not->toHaveKey('markup')->not->toHaveKey('supplier_cost');
});

it('manages providers without exposing secrets and refuses env-disabled providers', function () {
    $this->patchJson('/api/v1/admin/providers/mock', ['settings' => ['scenario' => 'no_results']])->assertOk()->assertJsonPath('data.settings.scenario', 'no_results');
    $this->patchJson('/api/v1/admin/providers/mock', ['settings' => ['scenario' => 'drop_tables']])->assertStatus(422);

    config(['providers.booking.mock.enabled' => false]);
    $this->patchJson('/api/v1/admin/providers/mock', ['is_enabled' => true])->assertStatus(422)->assertJsonPath('code', 'PROVIDER_DISABLED_BY_ENVIRONMENT');

    $this->postJson('/api/v1/admin/providers/mock/health-check')->assertOk()->assertJsonPath('data.health.status', 'healthy');
    expect(AuditLog::query()->where('action', 'provider.updated')->exists())->toBeTrue();
});

it('records exchange rates as append-only history', function () {
    $this->postJson('/api/v1/admin/exchange-rates', ['base_currency' => 'USD', 'quote_currency' => 'IQD', 'rate' => '1305.5'])->assertCreated();
    $this->postJson('/api/v1/admin/exchange-rates', ['base_currency' => 'USD', 'quote_currency' => 'USD', 'rate' => '1'])->assertStatus(422);

    expect(app(CurrencyConverter::class)->rate('USD', 'IQD')->__toString())->toBe('1305.500000');
});

it('reports system health and exposes audit logs', function () {
    $this->getJson('/api/v1/admin/system/health')->assertOk()
        ->assertJsonPath('checks.database.status', 'ok')
        ->assertJsonPath('checks.storage.status', 'ok')
        ->assertJsonStructure(['providers' => [['code', 'enabled', 'status', 'errors_24h']]]);

    $this->getJson('/api/v1/admin/audit-logs')->assertOk()->assertJsonStructure(['data', 'meta']);
});
