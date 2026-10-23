<?php

namespace App\Domain\Finance;

use App\Domain\Finance\Models\LedgerAccount;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Tenancy\TenantScope;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;

class AccountService
{
    public function walletFor(Tenant $tenant, ?string $currency = null): LedgerAccount
    {
        $currency = strtoupper($currency ?? $tenant->default_currency);

        return $this->firstOrCreate($tenant->id, LedgerAccount::AGENCY_WALLET, $currency, [
            'name' => "Wallet - {$tenant->name}",
            'normal_side' => 'credit',
        ]);
    }

    public function system(string $code, string $currency): LedgerAccount
    {
        $definition = LedgerAccount::SYSTEM_ACCOUNTS[$code];

        return $this->firstOrCreate(null, $code, strtoupper($currency), $definition);
    }

    /**
     * Pre-check without locking (UI feedback, hold creation). The authoritative check is
     * assertCanSpend() on a locked account inside the charging transaction.
     */
    public function assertCanSpend(LedgerAccount $wallet, Money $amount): void
    {
        if ($wallet->isFrozen()) {
            throw BusinessRuleException::make('ACCOUNT_FROZEN', __('errors.account_frozen'), 422);
        }

        $available = $wallet->available();
        if ($available->lessThan($amount)) {
            throw BusinessRuleException::make('INSUFFICIENT_CREDIT', __('errors.insufficient_credit'), 422, [
                'required' => $amount->jsonSerialize(),
                'available' => $available->jsonSerialize(),
                'balance' => $wallet->balanceMoney()->jsonSerialize(),
                'credit_limit' => $wallet->creditLimitMoney()->jsonSerialize(),
            ]);
        }
    }

    public function lockWallet(LedgerAccount $wallet): LedgerAccount
    {
        return LedgerAccount::query()->withoutGlobalScope(TenantScope::class)->lockForUpdate()->findOrFail($wallet->id);
    }

    /** @param array<string, string> $attributes */
    private function firstOrCreate(?int $tenantId, string $code, string $currency, array $attributes): LedgerAccount
    {
        $query = fn () => LedgerAccount::query()->withoutGlobalScope(TenantScope::class)
            ->where('code', $code)->where('currency', $currency)
            ->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId));

        if ($account = $query()->first()) {
            return $account;
        }

        try {
            return LedgerAccount::query()->withoutGlobalScope(TenantScope::class)->create($attributes + [
                'tenant_id' => $tenantId,
                'code' => $code,
                'currency' => $currency,
                'balance' => '0.00',
                'credit_limit' => '0.00',
                'status' => 'active',
            ]);
        } catch (UniqueConstraintViolationException) {
            return $query()->firstOrFail();
        }
    }
}
