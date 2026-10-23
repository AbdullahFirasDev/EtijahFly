<?php

namespace App\Http\Resources;

use App\Domain\Bookings\Models\Booking;
use App\Domain\Bookings\Models\BookingEvent;
use App\Domain\Bookings\Models\BookingPassenger;
use App\Domain\Ticketing\Models\BookingDocument;
use App\Http\Resources\Concerns\DetectsAudience;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Booking */
class BookingResource extends JsonResource
{
    use DetectsAudience;

    public function toArray(Request $request): array
    {
        $internal = $this->isInternal($request);

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'ticketing_status' => $this->ticketing_status?->value,
            'is_test' => $this->is_test,
            'provider' => $this->provider_code,
            'pnr' => $this->pnr,
            'trip_type' => $this->trip_type,
            'cabin' => $this->cabin,
            'route' => $this->route(),
            'itinerary' => $this->itinerary,
            'baggage' => $this->baggage,
            'fare_rules' => $this->fare_rules,
            'refundable' => $this->refundable,
            'passenger_counts' => $this->passenger_counts,
            'contact' => ['email' => $this->contact_email, 'phone' => $this->contact_phone],
            'price' => [
                'currency' => $this->currency,
                'total' => (string) $this->sale_total,
                'commission' => (string) $this->commission_amount,
                'agency_net' => (string) $this->agency_net,
            ],
            'settlement' => [
                'account_currency' => $this->account_currency,
                'fx_rate' => $this->fx_rate !== null ? (string) $this->fx_rate : null,
                'charged_amount' => $this->charged_amount !== null ? (string) $this->charged_amount : null,
                'commission_credited' => $this->commission_credited !== null ? (string) $this->commission_credited : null,
            ],
            'internal' => $this->when($internal, fn () => [
                'tenant_id' => $this->tenant_id,
                'tenant' => $this->whenLoaded('tenant', fn () => ['id' => $this->tenant->id, 'name' => $this->tenant->name]),
                'provider_booking_id' => $this->provider_booking_id,
                'supplier_base' => (string) $this->supplier_base,
                'supplier_taxes' => (string) $this->supplier_taxes,
                'supplier_total' => (string) $this->supplier_total,
                'markup' => (string) $this->markup_amount,
                'platform_profit' => (string) $this->platform_profit,
                'pricing_rules' => [
                    'markup' => $this->pricing['markup_rule'] ?? null,
                    'commission' => $this->pricing['commission_rule'] ?? null,
                ],
            ]),
            'hold_expires_at' => $this->hold_expires_at?->toIso8601String(),
            'ticketing_deadline' => $this->ticketing_deadline?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'ticketed_at' => $this->ticketed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'failure' => $this->failure_code ? ['code' => $this->failure_code, 'reason' => $this->failure_reason] : null,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? ['id' => $this->creator->id, 'name' => $this->creator->name] : null),
            'passengers' => $this->whenLoaded('passengers', fn () => $this->passengers->map(fn (BookingPassenger $p) => [
                'id' => $p->id,
                'type' => $p->type->value,
                'title' => $p->title,
                'first_name' => $p->first_name,
                'last_name' => $p->last_name,
                'gender' => $p->gender,
                'date_of_birth' => $p->date_of_birth->toDateString(),
                'nationality' => $p->nationality,
                'document_type' => $p->document_type,
                'document_number_masked' => $p->maskedDocument(),
                'document_expiry' => $p->document_expiry->toDateString(),
                'ticket_number' => $p->ticket_number,
            ])),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn (BookingEvent $e) => [
                'id' => $e->id,
                'type' => $e->type,
                'from_status' => $e->from_status,
                'to_status' => $e->to_status,
                'message' => $e->message,
                'actor_type' => $e->actor_type,
                'actor' => $e->actor ? ['id' => $e->actor->id, 'name' => $e->actor->name] : null,
                'created_at' => $e->created_at->toIso8601String(),
            ])),
            'documents' => $this->whenLoaded('documents', fn () => $this->documents->sortByDesc('id')->unique('type')->values()->map(fn (BookingDocument $d) => [
                'id' => $d->id,
                'type' => $d->type,
                'filename' => $d->filename,
                'booking_status' => $d->booking_status,
                'created_at' => $d->created_at?->toIso8601String(),
            ])),
            'refunds' => $this->whenLoaded('refunds', fn () => RefundResource::collection($this->refunds)),
            'actions' => $this->when($request->user() !== null && ! $internal, fn () => [
                'can_issue' => (bool) (in_array($this->status->value, ['on_hold', 'pending'], true) && $request->user()->can('issue', $this->resource)),
                'can_cancel' => (bool) (in_array($this->status->value, ['pending', 'on_hold'], true) && $request->user()->can('cancel', $this->resource)),
                'can_refund' => (bool) ($this->status->value === 'ticketed' && $request->user()->can('refund', $this->resource)),
            ]),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
