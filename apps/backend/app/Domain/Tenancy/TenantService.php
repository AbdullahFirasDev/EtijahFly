<?php

namespace App\Domain\Tenancy;

use App\Domain\AuditLog\Auditor;
use App\Domain\Finance\AccountService;
use App\Domain\Finance\Models\LedgerAccount;
use App\Domain\Identity\InvitationService;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Money\Money;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantBranding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class TenantService
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly InvitationService $invitations,
        private readonly Auditor $auditor,
    ) {}

    /**
     * Onboard an agency: tenant + default branding + wallet + owner invitation.
     *
     * @param  array<string, mixed>  $data
     * @return array{tenant: Tenant, invitation_id: int}
     */
    public function create(array $data, User $admin): array
    {
        return DB::transaction(function () use ($data, $admin) {
            $tenant = Tenant::query()->create([
                'name' => $data['name'],
                'legal_name' => $data['legal_name'] ?? null,
                'slug' => $data['slug'] ?? $this->uniqueSlug($data['name']),
                'status' => TenantStatus::Active,
                'country_code' => $data['country_code'] ?? 'IQ',
                'default_currency' => $data['default_currency'],
                'contact_email' => mb_strtolower($data['contact_email']),
                'contact_phone' => $data['contact_phone'] ?? null,
                'address' => $data['address'] ?? null,
                'custom_domain' => $data['custom_domain'] ?? null,
                'timezone' => $data['timezone'] ?? 'Asia/Baghdad',
                'locale' => $data['locale'] ?? 'ar',
            ]);

            TenantBranding::query()->withoutGlobalScopes()->create(['tenant_id' => $tenant->id] + TenantBranding::defaultsFor($tenant));

            $wallet = $this->accounts->walletFor($tenant);
            if (isset($data['credit_limit'])) {
                $wallet->forceFill(['credit_limit' => Money::of((string) $data['credit_limit'], $wallet->currency)->toDecimal()])->save();
            }

            $invite = $this->invitations->invite($tenant, $data['owner_email'], $data['owner_name'] ?? null, ['company_owner'], $admin);

            $this->auditor->record('tenant.created', $tenant, new: [
                'name' => $tenant->name, 'currency' => $tenant->default_currency, 'credit_limit' => $wallet->credit_limit,
            ], tenantId: $tenant->id, actor: $admin);

            return ['tenant' => $tenant, 'invitation_id' => $invite['invitation']->id];
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Tenant $tenant, array $data): Tenant
    {
        $tenant->fill($data)->save();
        [$old, $new] = Auditor::diff($tenant);
        if ($new) {
            $this->auditor->record('tenant.updated', $tenant, $old, $new, tenantId: $tenant->id);
        }

        return $tenant;
    }

    public function suspend(Tenant $tenant, string $reason): Tenant
    {
        $tenant->forceFill(['status' => TenantStatus::Suspended, 'suspended_at' => now(), 'suspension_reason' => $reason])->save();

        // Terminate every session of the agency's users.
        PersonalAccessToken::query()
            ->where('tokenable_type', (new User)->getMorphClass())
            ->whereIn('tokenable_id', User::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->select('id'))
            ->delete();

        $this->auditor->record('tenant.suspended', $tenant, new: ['reason' => $reason], tenantId: $tenant->id);

        return $tenant;
    }

    public function activate(Tenant $tenant): Tenant
    {
        $tenant->forceFill(['status' => TenantStatus::Active, 'suspended_at' => null, 'suspension_reason' => null])->save();
        $this->auditor->record('tenant.activated', $tenant, tenantId: $tenant->id);

        return $tenant;
    }

    public function setCreditLimit(Tenant $tenant, string $currency, Money $limit, string $reason, ?string $lowBalanceThreshold, User $admin): LedgerAccount
    {
        return DB::transaction(function () use ($tenant, $currency, $limit, $reason, $lowBalanceThreshold, $admin) {
            $wallet = $this->accounts->lockWallet($this->accounts->walletFor($tenant, $currency));
            $before = ['credit_limit' => (string) $wallet->credit_limit, 'low_balance_threshold' => $wallet->low_balance_threshold];

            $wallet->forceFill([
                'credit_limit' => $limit->toDecimal(),
                'low_balance_threshold' => $lowBalanceThreshold,
            ])->save();

            $this->auditor->record('finance.credit_limit_changed', $wallet, $before, [
                'credit_limit' => $limit->toDecimal(), 'low_balance_threshold' => $lowBalanceThreshold, 'reason' => $reason,
            ], tenantId: $tenant->id, actor: $admin);

            return $wallet;
        });
    }

    public function setAccountStatus(Tenant $tenant, string $currency, string $status, string $reason, User $admin): LedgerAccount
    {
        $wallet = $this->accounts->walletFor($tenant, $currency);
        $before = $wallet->status;
        $wallet->forceFill(['status' => $status])->save();
        $this->auditor->record('finance.account_status_changed', $wallet, ['status' => $before], ['status' => $status, 'reason' => $reason], tenantId: $tenant->id, actor: $admin);

        return $wallet;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'agency';
        $slug = $base;
        while (Tenant::query()->withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(4));
        }

        return $slug;
    }
}
