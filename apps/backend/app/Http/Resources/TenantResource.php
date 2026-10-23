<?php

namespace App\Http\Resources;

use App\Domain\Finance\Models\LedgerAccount;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tenant */
class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'country_code' => $this->country_code,
            'default_currency' => $this->default_currency,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'address' => $this->address,
            'custom_domain' => $this->custom_domain,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'suspension_reason' => $this->suspension_reason,
            'accounts' => $this->whenLoaded('ledgerAccounts', fn () => $this->ledgerAccounts
                ->where('code', LedgerAccount::AGENCY_WALLET)
                ->map(fn (LedgerAccount $a) => (new LedgerAccountResource($a))->toArray($request))->values()),
            'users_count' => $this->whenCounted('users'),
            'branding' => $this->whenLoaded('branding', fn () => $this->branding ? new BrandingResource($this->branding) : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
