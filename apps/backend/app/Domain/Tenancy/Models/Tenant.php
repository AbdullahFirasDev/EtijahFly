<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Finance\Models\LedgerAccount;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Enums\TenantStatus;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A travel agency / company using the platform (B2B customer, white-label tenant).
 *
 * @property int $id
 * @property string $name
 * @property string|null $legal_name
 * @property string $slug
 * @property TenantStatus $status
 * @property string $country_code
 * @property string $default_currency
 * @property string $contact_email
 * @property string|null $contact_phone
 * @property string|null $address
 * @property string|null $custom_domain
 * @property string $timezone
 * @property string $locale
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $suspended_at
 * @property string|null $suspension_reason
 * @property-read TenantBranding|null $branding
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'legal_name', 'slug', 'status', 'country_code', 'default_currency', 'contact_email',
        'contact_phone', 'address', 'custom_domain', 'timezone', 'locale', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'settings' => 'array',
            'suspended_at' => 'datetime',
        ];
    }

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasOne<TenantBranding, $this> */
    public function branding(): HasOne
    {
        return $this->hasOne(TenantBranding::class);
    }

    /** @return HasMany<LedgerAccount, $this> */
    public function ledgerAccounts(): HasMany
    {
        return $this->hasMany(LedgerAccount::class);
    }

    public function walletAccount(?string $currency = null): ?LedgerAccount
    {
        return $this->ledgerAccounts()
            ->where('code', LedgerAccount::AGENCY_WALLET)
            ->where('currency', $currency ?? $this->default_currency)
            ->first();
    }
}
