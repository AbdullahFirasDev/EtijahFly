<?php

use App\Domain\Bookings\Models\Booking;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Ticketing\DocumentService;
use App\Domain\Ticketing\Jobs\GenerateBookingDocuments;
use App\Domain\Ticketing\Jobs\GenerateInvoiceDocument;
use App\Domain\Ticketing\Models\BookingDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Queue::fake([GenerateBookingDocuments::class, GenerateInvoiceDocument::class]);
    $this->tenant = agency('USD', '5000.00');
    actingAsPartner(agencyUser($this->tenant, 'company_owner'));
    $this->booking = createBooking(firstOffer())->assertCreated()->json('data');
});

it('labels a confirmation as not being an e-ticket and shows no ticket numbers before issuance', function () {
    $html = app(DocumentService::class)->renderConfirmationHtml(Booking::query()->find($this->booking['id']));

    expect($html)->toContain('It is NOT an electronic ticket')
        ->toContain('وليس تذكرة سفر إلكترونية')
        ->toContain('No e-ticket has been issued yet')
        ->toContain($this->booking['reference'])
        ->toContain('TEST BOOKING from a simulated provider')
        ->not->toContain('E-ticket number')
        ->and(preg_match('/000\d{10}/', $html))->toBe(0);
});

it('refuses to generate an e-ticket before the provider has issued tickets', function () {
    $this->postJson("/api/v1/partner/bookings/{$this->booking['id']}/documents/e_ticket")
        ->assertStatus(409)->assertJsonPath('code', 'NOT_TICKETED');

    expect(BookingDocument::query()->where('type', 'e_ticket')->count())->toBe(0);
});

it('generates a branded confirmation PDF and streams it only to authorised users', function () {
    $document = $this->postJson("/api/v1/partner/bookings/{$this->booking['id']}/documents/booking_confirmation")
        ->assertCreated()->json('document');

    $record = BookingDocument::query()->findOrFail($document['id']);
    expect($record->booking_status)->toBe('on_hold')->and($record->size)->toBeGreaterThan(1000);
    Storage::disk('local')->assertExists('documents/'.$record->path);

    $pdf = $this->get("/api/v1/partner/bookings/{$this->booking['id']}/documents/{$document['id']}/file")
        ->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
    expect(substr($pdf, 0, 5))->toBe('%PDF-')->and(hash('sha256', $pdf))->toBe($record->sha256);

    // another agency cannot fetch it
    actingAsPartner(agencyUser(agency(), 'company_owner'));
    $this->get("/api/v1/partner/bookings/{$this->booking['id']}/documents/{$document['id']}/file")->assertNotFound();
});

it('produces an e-ticket with provider ticket numbers and a TEST watermark after issuance', function () {
    issueBooking($this->booking['id'])->assertOk()->assertJsonPath('data.status', 'ticketed');
    $booking = Booking::query()->find($this->booking['id']);
    $ticket = $booking->tickets()->sole();

    $html = app(DocumentService::class)->renderETicketHtml($booking);
    expect($html)->toContain(substr($ticket->ticket_number, 3))
        ->toContain('Electronic Ticket')
        ->toContain('TEST TICKET from a simulated provider');

    $doc = app(DocumentService::class)->generateETicket($booking);
    expect($doc->type)->toBe('e_ticket')->and(substr(app(DocumentService::class)->contents($doc), 0, 5))->toBe('%PDF-');

    // confirmation after ticketing still says it is not the ticket
    expect(app(DocumentService::class)->renderConfirmationHtml($booking))->toContain('It is NOT an electronic ticket')->toContain('Official ticket numbers appear on the e-ticket');
});

it('applies the agency branding to documents', function () {
    $this->putJson('/api/v1/partner/settings/branding', [
        'display_name' => 'Rafidain Premium Travel', 'primary_color' => '#123456', 'secondary_color' => '#654321', 'accent_color' => '#ABCDEF',
        'support_email' => 'help@rafidain.example', 'document_footer' => 'Licensed by the Ministry of Tourism',
    ])->assertOk();
    $this->post('/api/v1/partner/settings/branding/logo', ['logo' => UploadedFile::fake()->image('logo.png', 300, 100)], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.has_logo', true);

    $html = app(DocumentService::class)->renderConfirmationHtml(Booking::query()->find($this->booking['id']));
    expect($html)->toContain('Rafidain Premium Travel')->toContain('#123456')->toContain('Licensed by the Ministry of Tourism')->toContain('data:image/png;base64,');
});

it('rejects SVG or non-image logos', function () {
    $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    $this->post('/api/v1/partner/settings/branding/logo', ['logo' => $svg], ['Accept' => 'application/json'])->assertStatus(422);

    $fakePng = UploadedFile::fake()->createWithContent('logo.png', '<?php echo 1;');
    $this->post('/api/v1/partner/settings/branding/logo', ['logo' => $fakePng], ['Accept' => 'application/json'])->assertStatus(422);
});

it('generates the invoice PDF with platform (not agency) branding', function () {
    Queue::fake([GenerateBookingDocuments::class]); // let invoice generation run
    issueBooking($this->booking['id'])->assertOk();

    $invoice = Invoice::query()->sole();
    expect($invoice->pdf_path)->not->toBeNull();
    $pdf = $this->get("/api/v1/partner/finance/invoices/{$invoice->id}/file")->assertOk()->getContent();
    expect(substr($pdf, 0, 5))->toBe('%PDF-');
});
