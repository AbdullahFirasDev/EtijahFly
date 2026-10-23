<?php

namespace App\Http\Resources;

use App\Domain\Commissions\Models\PricingRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PricingRule */
class PricingRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'tenant' => $this->whenLoaded('tenant', fn () => $this->tenant?->only(['id', 'name'])),
            'name' => $this->name,
            'type' => $this->type,
            'calculation' => $this->calculation,
            'value' => (string) $this->value,
            'currency' => $this->currency,
            'basis' => $this->basis,
            'min_amount' => $this->min_amount !== null ? (string) $this->min_amount : null,
            'max_amount' => $this->max_amount !== null ? (string) $this->max_amount : null,
            'airline_codes' => $this->airline_codes,
            'cabin' => $this->cabin,
            'trip_type' => $this->trip_type,
            'origin' => $this->origin,
            'destination' => $this->destination,
            'provider_code' => $this->provider_code,
            'priority' => $this->priority,
            'is_active' => $this->is_active,
            'valid_from' => $this->valid_from?->toIso8601String(),
            'valid_to' => $this->valid_to?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
