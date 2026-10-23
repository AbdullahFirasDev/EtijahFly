<?php

namespace App\Http\Resources;

use App\Domain\Payments\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'tenant' => $this->whenLoaded('tenant', fn () => ['id' => $this->tenant->id, 'name' => $this->tenant->name]),
            'reference' => $this->reference,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'method' => $this->method,
            'gateway' => $this->gateway,
            'external_reference' => $this->external_reference,
            'paid_at' => $this->paid_at->toDateString(),
            'has_proof' => $this->proof_path !== null,
            'status' => $this->status,
            'notes' => $this->notes,
            'review_notes' => $this->review_notes,
            'submitted_by' => $this->whenLoaded('submitter', fn () => $this->submitter?->only(['id', 'name'])),
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->only(['id', 'name'])),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
