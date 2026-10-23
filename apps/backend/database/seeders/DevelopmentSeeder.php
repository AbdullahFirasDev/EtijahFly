<?php

namespace Database\Seeders;

use App\Domain\Commissions\Models\ExchangeRate;
use App\Domain\Commissions\Models\PricingRule;
use App\Domain\Finance\AccountService;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Payments\PaymentService;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantBranding;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Demo data for local development and automated tests ONLY. Refuses to run elsewhere.
 * Password: DEV_SEED_PASSWORD from .env, or a random one printed once.
 */
class DevelopmentSeeder extends Seeder
{
    public function run(AccountService $accounts, PaymentService $payments): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DevelopmentSeeder may only run in local/testing environments.');
        }

        $password = config('platform.dev_seed_password') ?: Str::password(16);

        $admin = $this->user(null, 'Platform Admin', 'admin@etijahfly.test', $password, 'super_admin');
        $this->user(null, 'Platform Staff', 'staff@etijahfly.test', $password, 'platform_staff');
        $this->user(null, 'Platform Finance', 'finance@etijahfly.test', $password, 'platform_finance');

        // Development placeholder rate - set the real rate in the admin portal.
        ExchangeRate::query()->firstOrCreate(
            ['base_currency' => 'USD', 'quote_currency' => 'IQD', 'source' => 'dev_seed'],
            ['rate' => '1310.000000', 'effective_at' => now()->subDay()],
        );

        PricingRule::query()->firstOrCreate(['name' => 'Default markup 5%'], [
            'type' => 'markup', 'calculation' => 'percent', 'value' => '5', 'basis' => 'supplier_total', 'priority' => 0, 'is_active' => true,
        ]);
        PricingRule::query()->firstOrCreate(['name' => 'Default commission 2% of base fare'], [
            'type' => 'commission', 'calculation' => 'percent', 'value' => '2', 'basis' => 'base_fare', 'priority' => 0, 'is_active' => true,
        ]);

        $agencies = [
            ['name' => 'Al-Rafidain Travel', 'slug' => 'rafidain', 'currency' => 'IQD', 'credit' => '5000000.00', 'deposit' => '15000000.00', 'primary' => '#0F4C81', 'secondary' => '#1B998B', 'domain' => 'rafidain.localhost'],
            ['name' => 'Babylon Tours', 'slug' => 'babylon', 'currency' => 'USD', 'credit' => '2000.00', 'deposit' => '8000.00', 'primary' => '#7C2D12', 'secondary' => '#B45309', 'domain' => 'babylon.localhost'],
        ];

        foreach ($agencies as $a) {
            $tenant = Tenant::query()->firstOrCreate(['slug' => $a['slug']], [
                'name' => $a['name'],
                'legal_name' => $a['name'].' LLC',
                'status' => TenantStatus::Active,
                'country_code' => 'IQ',
                'default_currency' => $a['currency'],
                'contact_email' => "contact@{$a['slug']}.test",
                'contact_phone' => '+9647700000000',
                'address' => 'Baghdad, Iraq',
                'custom_domain' => $a['domain'],
            ]);

            TenantBranding::query()->withoutGlobalScopes()->firstOrCreate(['tenant_id' => $tenant->id], [
                'display_name' => $a['name'],
                'primary_color' => $a['primary'],
                'secondary_color' => $a['secondary'],
                'accent_color' => '#F4A259',
                'support_email' => "support@{$a['slug']}.test",
                'support_phone' => '+9647700000000',
                'address' => 'Baghdad, Iraq',
                'document_footer' => "{$a['name']} â€” IATA-accredited agency (demo data)",
            ]);

            $owner = $this->user($tenant, "{$a['name']} Owner", "owner@{$a['slug']}.test", $password, 'company_owner');
            $this->user($tenant, "{$a['name']} Agent", "agent@{$a['slug']}.test", $password, 'booking_agent');
            $this->user($tenant, "{$a['name']} Accountant", "accountant@{$a['slug']}.test", $password, 'accountant');

            $wallet = $accounts->walletFor($tenant);
            if ($wallet->balance == 0 && $wallet->credit_limit == 0) {
                $wallet->forceFill(['credit_limit' => $a['credit']])->save();
                $payment = $payments->submit($owner, [
                    'amount' => $a['deposit'], 'currency' => $a['currency'], 'method' => 'bank_transfer',
                    'paid_at' => now()->toDateString(), 'external_reference' => 'DEV-OPENING', 'notes' => 'Opening deposit (demo)',
                ], null, "dev-opening-{$tenant->id}");
                if ($payment->status === 'pending') {
                    $payments->approve($payment, $admin, 'Demo opening balance');
                }
            }
        }

        $this->command?->info('Development users created (password from DEV_SEED_PASSWORD or below):');
        $this->command?->table(['Portal', 'E-mail', 'Role'], [
            ['admin', 'admin@etijahfly.test', 'super_admin'],
            ['admin', 'staff@etijahfly.test', 'platform_staff'],
            ['admin', 'finance@etijahfly.test', 'platform_finance'],
            ['partner', 'owner@rafidain.test / owner@babylon.test', 'company_owner'],
            ['partner', 'agent@rafidain.test / agent@babylon.test', 'booking_agent'],
            ['partner', 'accountant@rafidain.test / accountant@babylon.test', 'accountant'],
        ]);
        if (! config('platform.dev_seed_password')) {
            $this->command?->warn("Generated password: {$password}");
        }
    }

    private function user(?Tenant $tenant, string $name, string $email, string $password, string $role): User
    {
        $user = User::query()->withoutGlobalScopes()->firstOrCreate(['email' => $email], [
            'tenant_id' => $tenant?->id,
            'is_platform' => $tenant === null,
            'name' => $name,
            'password' => $password,
            'password_changed_at' => now(),
            'email_verified_at' => now(),
            'status' => UserStatus::Active,
            'locale' => 'ar',
        ]);
        $user->syncRoles([$role]);

        return $user;
    }
}
