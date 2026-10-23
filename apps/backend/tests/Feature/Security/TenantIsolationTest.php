<?php

use App\Domain\Bookings\Models\Booking;
use App\Domain\Bookings\Models\Traveler;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Identity\Models\User;
use App\Domain\Payments\Models\Payment;
use App\Domain\Shared\Tenancy\TenantContext;
use App\Domain\Ticketing\Jobs\GenerateBookingDocuments;
use App\Domain\Ticketing\Jobs\GenerateInvoiceDocument;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([GenerateBookingDocuments::class, GenerateInvoiceDocument::class]);
    $this->a = agency('USD', '5000.00');
    $this->b = agency('USD', '5000.00');

    // Agency B creates real data
    actingAsPartner(agencyUser($this->b, 'company_owner'));
    $this->bBooking = createBooking(firstOffer())->assertCreated()->json('data');
    issueBooking($this->bBooking['id'])->assertOk();
    $this->bTraveler = $this->postJson('/api/v1/partner/travelers', ['first_name' => 'Zainab', 'last_name' => 'Kareem', 'document_number' => 'B9876543'])->assertCreated()->json('data');
    $this->bOffer = firstOffer();

    // Agency A's owner (has every tenant permission) is the attacker
    actingAsPartner(agencyUser($this->a, 'company_owner'));
});

it('returns 404 for another agency\'s records, never their data', function (string $method, string $uri) {
    $uri = strtr($uri, [
        '{booking}' => $this->bBooking['id'],
        '{traveler}' => $this->bTraveler['id'],
        '{offer}' => $this->bOffer['id'],
        '{invoice}' => Invoice::query()->withoutGlobalScopes()->where('tenant_id', $this->b->id)->value('id'),
    ]);

    $response = $this->json($method, $uri, ['reason' => 'attack']);
    $response->assertNotFound();
    expect($response->getContent())->not->toContain($this->bBooking['reference']);
})->with([
    'view booking' => ['GET', '/api/v1/partner/bookings/{booking}'],
    'cancel booking' => ['POST', '/api/v1/partner/bookings/{booking}/cancel'],
    'view traveler' => ['GET', '/api/v1/partner/travelers/{traveler}'],
    'delete traveler' => ['DELETE', '/api/v1/partner/travelers/{traveler}'],
    'view offer' => ['GET', '/api/v1/partner/flights/offers/{offer}'],
    'price check offer' => ['POST', '/api/v1/partner/flights/offers/{offer}/price-check'],
    'download invoice' => ['GET', '/api/v1/partner/finance/invoices/{invoice}/file'],
]);

it('cannot issue or book with another agency\'s resources', function () {
    $this->withHeader('Idempotency-Key', 'cross-tenant-issue')->postJson("/api/v1/partner/bookings/{$this->bBooking['id']}/issue")->assertNotFound();
    createBooking($this->bOffer)->assertNotFound();
});

it('only lists the caller\'s own records', function () {
    expect($this->getJson('/api/v1/partner/bookings')->assertOk()->json('data'))->toBeEmpty()
        ->and($this->getJson('/api/v1/partner/travelers')->assertOk()->json('data'))->toBeEmpty()
        ->and($this->getJson('/api/v1/partner/finance/invoices')->assertOk()->json('data'))->toBeEmpty()
        ->and($this->getJson('/api/v1/partner/finance/payments')->assertOk()->json('data'))->toBeEmpty()
        ->and(collect($this->getJson('/api/v1/partner/team/users')->assertOk()->json('data'))->pluck('tenant_id')->unique()->all())->toBe([$this->a->id]);

    $statement = $this->getJson('/api/v1/partner/finance/statement')->assertOk()->json();
    expect($statement['account']['tenant_id'])->toBe($this->a->id)->and($statement['closing_balance'])->toBe('5000.00');
});

it('cannot manage another agency\'s staff or roles', function () {
    $bUser = User::query()->withoutGlobalScopes()->where('tenant_id', $this->b->id)->first();

    $this->putJson("/api/v1/partner/team/users/{$bUser->id}/status", ['status' => 'disabled'])->assertNotFound();
    $this->putJson("/api/v1/partner/team/users/{$bUser->id}/roles", ['roles' => ['accountant']])->assertNotFound();
});

it('refuses to write a record for another tenant even if code tried to', function () {
    app(TenantContext::class)->set($this->a->id);
    $traveler = Traveler::query()->withoutGlobalScopes()->find($this->bTraveler['id']);

    expect(fn () => $traveler->update(['notes' => 'tampered']))->toThrow(LogicException::class);
});

it('scopes every tenant model query to the current tenant', function () {
    app(TenantContext::class)->set($this->a->id);

    expect(Booking::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and(Booking::query()->withoutGlobalScopes()->count())->toBe(1);
});
