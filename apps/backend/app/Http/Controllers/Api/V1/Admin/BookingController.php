<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Bookings\CancellationService;
use App\Domain\Bookings\Enums\RefundStatus;
use App\Domain\Bookings\Models\Booking;
use App\Domain\Bookings\Models\Refund;
use App\Domain\Bookings\RefundService;
use App\Domain\Shared\Money\Money;
use App\Domain\Ticketing\DocumentService;
use App\Domain\Ticketing\Jobs\VerifyTicketing;
use App\Domain\Ticketing\Models\BookingDocument;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Http\Resources\RefundResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/** Cross-tenant booking operations for platform staff. */
class BookingController extends Controller
{
    public function __construct(
        private readonly CancellationService $cancellations,
        private readonly RefundService $refunds,
        private readonly DocumentService $documents,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('platform.bookings.view');
        $f = $request->validate([
            'status' => ['nullable', 'string', 'max:60'],
            'ticketing_status' => ['nullable', 'string', 'max:60'],
            'tenant_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:60'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return BookingResource::collection(Booking::query()
            ->when($f['status'] ?? null, fn (Builder $q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($f['ticketing_status'] ?? null, fn (Builder $q, $s) => $q->whereIn('ticketing_status', explode(',', $s)))
            ->when($f['tenant_id'] ?? null, fn (Builder $q, $id) => $q->where('tenant_id', $id))
            ->when($f['q'] ?? null, fn (Builder $q, $t) => $q->where(fn (Builder $w) => $w->where('reference', 'ilike', "%{$t}%")->orWhere('pnr', 'ilike', "%{$t}%")))
            ->when($f['from'] ?? null, fn (Builder $q, $d) => $q->where('created_at', '>=', $d))
            ->when($f['to'] ?? null, fn (Builder $q, $d) => $q->where('created_at', '<', Carbon::parse($d)->addDay()))
            ->with(['tenant:id,name', 'creator'])
            ->latest('id')
            ->paginate(25));
    }

    public function show(Booking $booking): BookingResource
    {
        $this->authorize('platform.bookings.view');

        return new BookingResource($booking->load(['tenant:id,name', 'passengers', 'events.actor', 'documents', 'refunds', 'creator']));
    }

    /** Re-run provider verification for a booking with an unconfirmed ticketing outcome. */
    public function verifyTicketing(Booking $booking): JsonResponse
    {
        $this->authorize('platform.bookings.manage');
        if ($booking->ticketing_status?->value === 'needs_attention') {
            $booking->forceFill(['ticketing_status' => 'pending_verification'])->save();
        }
        VerifyTicketing::dispatch($booking->id, 1);

        return response()->json(['message' => 'queued']);
    }

    public function cancel(Request $request, Booking $booking): BookingResource
    {
        $this->authorize('platform.bookings.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return new BookingResource($this->cancellations->cancel($booking, $request->user(), $data['reason'])->load(['passengers', 'events.actor']));
    }

    public function refunds(Request $request): AnonymousResourceCollection
    {
        $this->authorize('platform.bookings.view');

        return RefundResource::collection(Refund::query()
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->latest('id')->paginate(25));
    }

    /**
     * Record an offline refund confirmed by the airline/BSP. The confirmation reference is
     * mandatory and the action is audited.
     */
    public function completeRefund(Request $request, Refund $refund): RefundResource
    {
        $this->authorize('platform.bookings.manage');
        $data = $request->validate([
            'supplier_refund_amount' => ['required', 'decimal:0,2', 'min:0'],
            'penalty_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'confirmation_reference' => ['required', 'string', 'max:120'],
        ]);

        $refund->load('booking');

        return new RefundResource($this->refunds->completeManually(
            $refund,
            $request->user(),
            Money::of((string) $data['supplier_refund_amount'], $refund->currency),
            isset($data['penalty_amount']) ? Money::of((string) $data['penalty_amount'], $refund->currency) : null,
            $data['confirmation_reference'],
        ));
    }

    public function rejectRefund(Request $request, Refund $refund): RefundResource
    {
        $this->authorize('platform.bookings.manage');
        abort_unless(in_array($refund->status, [RefundStatus::Requested, RefundStatus::Pending], true), 409);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $refund->load('booking');

        return new RefundResource($this->refunds->rejectManually($refund, $request->user(), $data['reason']));
    }

    public function documentFile(Booking $booking, BookingDocument $document): Response
    {
        $this->authorize('platform.bookings.view');
        abort_unless($document->booking_id === $booking->id, 404);

        return response($this->documents->contents($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$document->filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
