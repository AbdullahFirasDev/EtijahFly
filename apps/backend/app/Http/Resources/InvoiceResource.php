<?php

namespace App\Http\Resources;

use App\Domain\Finance\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invoice */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'number' => $this->number,
            'type' => $this->type,
            'booking_id' => $this->booking_id,
            'booking_reference' => $this->whenLoaded('booking', fn () => $this->booking?->reference),
            'currency' => $this->currency,
            'subtotal' => (string) $this->subtotal,
            'total' => (string) $this->total,
            'lines' => $this->lines,
            'status' => $this->status,
            'has_pdf' => $this->pdf_path !== null,
            'issued_at' => $this->issued_at->toIso8601String(),
        ];
    }
}
