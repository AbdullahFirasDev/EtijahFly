<?php

use App\Domain\Finance\AccountService;
use App\Domain\Finance\Data\LedgerLine;
use App\Domain\Finance\LedgerService;
use App\Domain\Finance\Models\LedgerAccount;
use App\Domain\Finance\Models\LedgerTransaction;
use App\Domain\Identity\Models\User;
use App\Domain\Providers\Models\FlightProvider;
use App\Domain\Shared\Money\Money;
use App\Domain\Tenancy\Models\Tenant;
use Database\Seeders\AirlineSeeder;
use Database\Seeders\AirportSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ProviderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        $this->seed([PermissionSeeder::class, ProviderSeeder::class, AirportSeeder::class, AirlineSeeder::class]);
    })
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit', 'External');

// ---------------------------------------------------------------- fixtures

/** Agency with a funded wallet (posted through the ledger, like a real payment). */
function agency(string $currency = 'USD', string $balance = '10000.00', string $credit = '0.00'): Tenant
{
    $tenant = Tenant::factory()->currency($currency)->create();
    $wallet = app(AccountService::class)->walletFor($tenant);
    $wallet->forceFill(['credit_limit' => $credit])->save();

    if (Money::of($balance, $currency)->isPositive()) {
        DB::transaction(fn () => app(LedgerService::class)->post(
            LedgerTransaction::TYPE_PAYMENT,
            [
                LedgerLine::debit(app(AccountService::class)->system(LedgerAccount::CASH_BANK, $currency), Money::of($balance, $currency)),
                LedgerLine::credit($wallet, Money::of($balance, $currency)),
            ],
            'Test funding',
            $tenant->id,
        ));
    }

    return $tenant;
}

function agencyUser(Tenant $tenant, string $role = 'company_owner'): User
{
    return User::factory()->forTenant($tenant)->withRole($role)->create();
}

function platformUser(string $role = 'super_admin'): User
{
    return User::factory()->platform()->withRole($role)->create();
}

function actingAsPartner(User $user): User
{
    Sanctum::actingAs($user, ['portal:partner']);

    return $user;
}

function actingAsAdmin(User $user): User
{
    Sanctum::actingAs($user, ['portal:admin']);

    return $user;
}

function wallet(Tenant $tenant, ?string $currency = null): LedgerAccount
{
    return app(AccountService::class)->walletFor($tenant, $currency)->refresh();
}

function mockScenario(string $scenario): void
{
    FlightProvider::query()->where('code', 'mock')->update(['settings' => json_encode(['scenario' => $scenario])]);
    app()->forgetScopedInstances();
}

/** @param array<string, mixed> $overrides */
function searchPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'trip_type' => 'one_way',
        'slices' => [['origin' => 'BGW', 'destination' => 'DXB', 'date' => now()->addDays(21)->toDateString()]],
        'passengers' => ['adults' => 1, 'children' => 0, 'infants' => 0],
        'cabin' => 'economy',
    ], $overrides);
}

/** Run a search as the current partner user and return the cheapest offer payload. */
function firstOffer(array $overrides = []): array
{
    $response = test()->postJson('/api/v1/partner/flights/search', searchPayload($overrides))->assertOk();

    return $response->json('offers.0');
}

/** @return array<string, mixed> */
function passenger(string $type = 'ADT', array $overrides = []): array
{
    $dob = match ($type) {
        'CHD' => now()->subYears(7)->toDateString(),
        'INF' => now()->subMonths(10)->toDateString(),
        default => now()->subYears(35)->toDateString(),
    };

    return array_merge([
        'type' => $type,
        'title' => $type === 'ADT' ? 'MR' : 'MSTR',
        'first_name' => 'Ali',
        'last_name' => 'Hassan',
        'gender' => 'M',
        'date_of_birth' => $dob,
        'nationality' => 'IQ',
        'document_type' => 'passport',
        'document_number' => 'A'.random_int(10000000, 99999999),
        'document_expiry' => now()->addYears(5)->toDateString(),
        'document_country' => 'IQ',
    ], $overrides);
}

function createBooking(array $offer, ?array $passengers = null, ?string $key = null): TestResponse
{
    return test()->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())->postJson('/api/v1/partner/bookings', [
        'offer_id' => $offer['id'],
        'expected_total' => $offer['price']['total'],
        'contact' => ['email' => 'traveler@example.com', 'phone' => '+9647701234567'],
        'passengers' => $passengers ?? [passenger()],
    ]);
}

function issueBooking(int $bookingId, ?string $key = null): TestResponse
{
    return test()->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())->postJson("/api/v1/partner/bookings/{$bookingId}/issue");
}

/** Sum of debits must equal sum of credits across the whole ledger. */
function assertLedgerBalanced(): void
{
    $totals = DB::table('ledger_entries')->selectRaw("sum(case when direction = 'debit' then amount else 0 end) as d, sum(case when direction = 'credit' then amount else 0 end) as c")->first();
    expect((string) $totals->d)->toBe((string) $totals->c);
}
