<?php

namespace App\Http\Resources;

use App\Domain\Bookings\Models\Refund;
use App\Http\Resources\Concerns\DetectsAudience;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Refund */
class RefundResource extends JsonResource
{
    use DetectsAudience;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'source' => $this->source,
            'currency' => $this->currency,
            'supplier_refund_amount' => $this->when($this->isInternal($request), fn () => $this->supplier_refund_amount !== null ? (string) $this->supplier_refund_amount : null),
            'penalty_amount' => $this->penalty_amount !== null ? (string) $this->penalty_amount : null,
            'account_currency' => $this->account_currency,
            'refund_fee' => (string) $this->refund_fee,
            'agency_refund_amount' => $this->agency_refund_amount !== null ? (string) $this->agency_refund_amount : null,
            'commission_clawback' => $this->commission_clawback !== null ? (string) $this->commission_clawback : null,
            'confirmation_reference' => $this->confirmation_reference,
            'failure_reason' => $this->failure_reason,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
