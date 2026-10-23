<?php

namespace App\Domain\Ticketing;

use App\Domain\Airlines\AirlineDirectory;
use App\Domain\Airlines\Models\Airport;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Models\Booking;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Shared\Storage\FileStorage;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantBranding;
use App\Domain\Ticketing\Models\BookingDocument;
use App\Domain\Ticketing\Models\Ticket;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Mpdf\Mpdf;
use Throwable;

/**
 * White-labelled PDF documents.
 *
 *  - Booking confirmation: issued by the platform on behalf of the agency for any booking
 *    state. It always states that it is NOT an electronic ticket.
 *  - E-ticket itinerary/receipt: only for bookings in `ticketed` (or later refund) state whose
 *    ticket numbers were returned by the provider. Refused otherwise.
 *  - Invoice / credit note for ledger postings.
 * Bookings made with sandbox providers are watermarked "TEST - NOT VALID FOR TRAVEL".
 */
class DocumentService
{
    public function __construct(
        private readonly FileStorage $storage,
        private readonly ViewFactory $views,
        private readonly AirlineDirectory $airlines,
    ) {}

    public function generateConfirmation(Booking $booking, ?User $by = null): BookingDocument
    {
        $html = $this->renderConfirmationHtml($booking);

        return $this->store($booking, BookingDocument::TYPE_CONFIRMATION, $this->pdf($html, $booking->is_test, "Booking {$booking->reference}"), "booking-confirmation-{$booking->reference}.pdf", $by);
    }

    public function generateETicket(Booking $booking, ?User $by = null): BookingDocument
    {
        $html = $this->renderETicketHtml($booking);

        return $this->store($booking, BookingDocument::TYPE_E_TICKET, $this->pdf($html, $booking->is_test, "E-ticket {$booking->reference}"), "e-ticket-{$booking->reference}.pdf", $by);
    }

    public function generateInvoice(Invoice $invoice): BookingDocument
    {
        $tenant = Tenant::query()->findOrFail($invoice->tenant_id);
        $booking = $invoice->booking_id ? Booking::query()->withoutGlobalScopes()->find($invoice->booking_id) : null;

        // Invoices are issued by the platform to the agency: platform branding, not white-label.
        $html = $this->views->make('pdf.invoice', [
            'invoice' => $invoice,
            'booking' => $booking,
            'tenant' => $tenant,
        ] + $this->platformBrandingData($tenant))->render();

        $type = $invoice->type === Invoice::TYPE_CREDIT_NOTE ? BookingDocument::TYPE_CREDIT_NOTE : BookingDocument::TYPE_INVOICE;
        $document = $this->storePdf($tenant->id, $booking, $type, $this->pdf($html, (bool) $booking?->is_test, $invoice->number), "{$invoice->number}.pdf", null, $invoice);
        $invoice->forceFill(['pdf_path' => $document->path])->save();

        return $document;
    }

    public function renderConfirmationHtml(Booking $booking): string
    {
        $booking->loadMissing('passengers');
        $tenant = Tenant::query()->findOrFail($booking->tenant_id);

        return $this->views->make('pdf.booking-confirmation', [
            'booking' => $booking,
            'tenant' => $tenant,
            'isTicketed' => in_array($booking->status, [BookingStatus::Ticketed, BookingStatus::RefundPending], true),
        ] + $this->itineraryData($booking) + $this->brandingData($tenant))->render();
    }

    public function renderETicketHtml(Booking $booking): string
    {
        $this->assertTicketed($booking);
        $tenant = Tenant::query()->findOrFail($booking->tenant_id);

        $tickets = Ticket::query()->withoutGlobalScopes()->where('booking_id', $booking->id)->with('passenger')->get();

        return $this->views->make('pdf.e-ticket', [
            'booking' => $booking,
            'tenant' => $tenant,
            'tickets' => $tickets,
        ] + $this->itineraryData($booking) + $this->brandingData($tenant))->render();
    }

    public function temporaryUrl(BookingDocument $document): string
    {
        return $this->storage->temporaryUrl(
            config('filestorage.buckets.documents'),
            $document->path,
            (int) config('filestorage.signed_url_ttl'),
            $document->filename,
        );
    }

    public function contents(BookingDocument $document): string
    {
        return $this->storage->get(config('filestorage.buckets.documents'), $document->path);
    }

    private function assertTicketed(Booking $booking): void
    {
        $hasTickets = Ticket::query()->withoutGlobalScopes()->where('booking_id', $booking->id)->exists();
        $ticketedStates = [BookingStatus::Ticketed, BookingStatus::RefundPending, BookingStatus::Refunded];

        if (! in_array($booking->status, $ticketedStates, true) || ! $hasTickets) {
            throw BusinessRuleException::make('NOT_TICKETED', __('errors.not_ticketed'), 409, ['status' => $booking->status->value]);
        }
    }

    /** @return array<string, mixed> */
    private function itineraryData(Booking $booking): array
    {
        $codes = collect($booking->itinerary)->flatMap(fn (array $slice) => collect($slice['segments'])->pluck('marketing_carrier'))->unique()->values()->all();
        $airports = collect($booking->itinerary)->flatMap(fn (array $slice) => collect($slice['segments'])->flatMap(fn ($s) => [$s['origin'], $s['destination']]))->unique()->values()->all();

        return [
            'airlineNames' => $this->airlines->namesFor($codes)->map(fn ($a) => $a->name)->all(),
            'airports' => Airport::query()->whereIn('iata_code', $airports)->get()->keyBy('iata_code'),
        ];
    }

    /** @return array<string, mixed> */
    private function brandingData(Tenant $tenant): array
    {
        $branding = TenantBranding::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $defaults = TenantBranding::defaultsFor($tenant);

        $logo = null;
        if ($branding?->logo_path) {
            try {
                $bytes = $this->storage->get(config('filestorage.buckets.branding'), $branding->logo_path);
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'image/png';
                $logo = 'data:'.$mime.';base64,'.base64_encode($bytes);
            } catch (Throwable) {
                $logo = null; // a missing logo must not block document generation
            }
        }

        return [
            'brand' => [
                'name' => $branding->display_name ?? $defaults['display_name'],
                'primary' => $branding->primary_color ?? $defaults['primary_color'],
                'secondary' => $branding->secondary_color ?? $defaults['secondary_color'],
                'accent' => $branding->accent_color ?? $defaults['accent_color'],
                'email' => $branding->support_email ?? $defaults['support_email'],
                'phone' => $branding->support_phone ?? $defaults['support_phone'],
                'website' => $branding?->website,
                'address' => $branding->address ?? $defaults['address'],
                'footer' => $branding?->document_footer,
                'logo' => $logo,
            ],
            'platformName' => config('platform.name'),
            'generatedAt' => now()->timezone($tenant->timezone),
        ];
    }

    /** @return array<string, mixed> */
    private function platformBrandingData(Tenant $tenant): array
    {
        return [
            'brand' => [
                'name' => config('platform.name'),
                'primary' => '#0F4C81',
                'secondary' => '#1B998B',
                'accent' => '#F4A259',
                'email' => config('mail.from.address'),
                'phone' => null,
                'website' => null,
                'address' => null,
                'footer' => null,
                'logo' => null,
            ],
            'platformName' => config('platform.name'),
            'generatedAt' => now()->timezone($tenant->timezone),
        ];
    }

    private function pdf(string $html, bool $isTest, string $title): string
    {
        $tmp = storage_path('app/mpdf');
        if (! is_dir($tmp)) {
            mkdir($tmp, 0775, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => $tmp,
            'directionality' => 'rtl',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'default_font' => 'dejavusans',
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 12,
            'margin_bottom' => 14,
        ]);
        $mpdf->SetTitle($title);
        $mpdf->SetCreator(config('platform.name'));

        if ($isTest) {
            $mpdf->SetWatermarkText('TEST - NOT VALID FOR TRAVEL', 0.12);
            $mpdf->showWatermarkText = true;
        }

        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }

    private function store(Booking $booking, string $type, string $pdf, string $filename, ?User $by): BookingDocument
    {
        return $this->storePdf($booking->tenant_id, $booking, $type, $pdf, $filename, $by);
    }

    private function storePdf(int $tenantId, ?Booking $booking, string $type, string $pdf, string $filename, ?User $by, ?Invoice $invoice = null): BookingDocument
    {
        $path = sprintf('%d/%s/%s-%s.pdf', $tenantId, $booking ? 'bookings/'.$booking->reference : 'invoices', $type, now()->format('YmdHisv'));
        $this->storage->put(config('filestorage.buckets.documents'), $path, $pdf, 'application/pdf');

        return BookingDocument::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking?->id,
            'invoice_id' => $invoice?->id,
            'type' => $type,
            'path' => $path,
            'filename' => $filename,
            'size' => strlen($pdf),
            'sha256' => hash('sha256', $pdf),
            'booking_status' => $booking?->status->value,
            'generated_by' => $by?->id,
        ]);
    }
}
