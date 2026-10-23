<?php

namespace App\Http\Resources;

use App\Domain\Finance\Models\LedgerAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LedgerAccount */
class LedgerAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'code' => $this->code,
            'currency' => $this->currency,
            'balance' => (string) $this->balance,
            'credit_limit' => (string) $this->credit_limit,
            'available' => $this->available()->toDecimal(),
            'outstanding' => $this->outstanding()->toDecimal(),
            'low_balance_threshold' => $this->low_balance_threshold !== null ? (string) $this->low_balance_threshold : null,
            'status' => $this->status,
        ];
    }
}
