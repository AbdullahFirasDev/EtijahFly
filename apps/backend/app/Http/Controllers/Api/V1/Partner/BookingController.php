<?php

namespace App\Http\Controllers\Api\V1\Partner;

use App\Domain\Bookings\BookingService;
use App\Domain\Bookings\CancellationService;
use App\Domain\Bookings\Models\Booking;
use App\Domain\Bookings\Models\Traveler;
use App\Domain\Bookings\RefundService;
use App\Domain\FlightSearch\Models\FlightOffer;
use App\Domain\Ticketing\DocumentService;
use App\Domain\Ticketing\Models\BookingDocument;
use App\Domain\Ticketing\TicketingService;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateBookingRequest;
use App\Http\Resources\BookingResource;
use App\Http\Resources\RefundResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class BookingController extends Controller
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly TicketingService $ticketing,
        private readonly CancellationService $cancellations,
        private readonly RefundService $refunds,
        private readonly DocumentService $documents,
    ) {}

    /** Company bookings (or only the user's own without bookings.view_all). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        abort_unless($user->can('bookings.view') || $user->can('bookings.view_all'), 403);

        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:20'],
            'q' => ['nullable', 'string', 'max:60'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $bookings = Booking::query()
            ->when(! $user->can('bookings.view_all'), fn (Builder $q) => $q->where('created_by', $user->id))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->whereIn('status', explode(',', $status)))
            ->when($filters['q'] ?? null, function (Builder $q, string $term) {
                $q->where(fn (Builder $w) => $w->where('reference', 'ilike', "%{$term}%")
                    ->orWhere('pnr', 'ilike', "%{$term}%")
                    ->orWhereHas('passengers', fn (Builder $p) => $p->where('last_name', 'ilike', "%{$term}%")));
            })
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->where('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->where('created_at', '<', Carbon::parse($to)->addDay()))
            ->with('creator')
            ->latest('id')
            ->paginate($filters['per_page'] ?? 20);

        return BookingResource::collection($bookings);
    }

    /**
     * Create a booking from a priced offer (re-checks price and credit, places a hold when
     * the provider supports it). Requires an Idempotency-Key header.
     */
    public function store(CreateBookingRequest $request): JsonResponse
    {
        $data = $request->validated();
        $offer = FlightOffer::query()->with('search')->findOrFail($data['offer_id']);

        $booking = $this->bookings->create(
            $request->user(),
            $offer,
            $data['passengers'],
            $data['contact'],
            (string) $data['expected_total'],
            (string) $request->attributes->get('idempotency_key'),
        );

        if ($request->boolean('save_travelers') && $request->user()->can('travelers.manage')) {
            $this->saveTravelers($data['passengers'], $request->user()->id);
        }

        return (new BookingResource($this->loadDetail($booking)))->response()->setStatusCode(201);
    }

    public function show(Booking $booking): BookingResource
    {
        $this->authorize('view', $booking);

        return new BookingResource($this->loadDetail($booking));
    }

    /** Charge the company account and request e-tickets. Requires an Idempotency-Key header. */
    public function issue(Request $request, Booking $booking): BookingResource
    {
        $this->authorize('issue', $booking);

        return new BookingResource($this->loadDetail($this->ticketing->issue($booking, $request->user())));
    }

    public function cancel(Request $request, Booking $booking): BookingResource
    {
        $this->authorize('cancel', $booking);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return new BookingResource($this->loadDetail($this->cancellations->cancel($booking, $request->user(), $data['reason'])));
    }

    public function refund(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('refund', $booking);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $refund = $this->refunds->request($booking, $request->user(), $data['reason']);

        return response()->json([
            'refund' => new RefundResource($refund),
            'booking' => new BookingResource($this->loadDetail($booking->refresh())),
        ]);
    }

    /** Generate (or regenerate) a document: booking_confirmation, or e_ticket once ticketed. */
    public function generateDocument(Request $request, Booking $booking, string $type): JsonResponse
    {
        $this->authorize('documents', $booking);
        $booking->load('passengers');

        $document = match ($type) {
            BookingDocument::TYPE_CONFIRMATION => $this->documents->generateConfirmation($booking, $request->user()),
            BookingDocument::TYPE_E_TICKET => $this->documents->generateETicket($booking, $request->user()),
            default => abort(404),
        };

        return response()->json(['document' => ['id' => $document->id, 'type' => $document->type, 'filename' => $document->filename]], 201);
    }

    /** Short-lived signed URL for a booking document. */
    public function documentUrl(Booking $booking, BookingDocument $document): JsonResponse
    {
        $this->authorize('documents', $booking);
        abort_unless($document->booking_id === $booking->id, 404);

        return response()->json([
            'url' => $this->documents->temporaryUrl($document),
            'expires_in' => (int) config('filestorage.signed_url_ttl'),
        ]);
    }

    /** Stream a booking document (used by the portal's server-side proxy). */
    public function documentFile(Booking $booking, BookingDocument $document): Response
    {
        $this->authorize('documents', $booking);
        abort_unless($document->booking_id === $booking->id, 404);

        return response($this->documents->contents($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$document->filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function loadDetail(Booking $booking): Booking
    {
        return $booking->load(['passengers', 'events.actor', 'documents', 'refunds', 'creator']);
    }

    /** @param list<array<string, mixed>> $passengers */
    private function saveTravelers(array $passengers, int $userId): void
    {
        foreach ($passengers as $p) {
            if (! empty($p['traveler_id'])) {
                continue;
            }
            Traveler::query()->firstOrCreate(
                ['first_name' => strtoupper($p['first_name']), 'last_name' => strtoupper($p['last_name']), 'date_of_birth' => $p['date_of_birth']],
                [
                    'title' => $p['title'] ?? null,
                    'gender' => $p['gender'],
                    'nationality' => strtoupper($p['nationality']),
                    'document_type' => $p['document_type'] ?? 'passport',
                    'document_number' => strtoupper($p['document_number']),
                    'document_expiry' => $p['document_expiry'],
                    'document_country' => strtoupper($p['document_country']),
                    'email' => $p['email'] ?? null,
                    'phone' => $p['phone'] ?? null,
                    'created_by' => $userId,
                ],
            );
        }
    }
}
