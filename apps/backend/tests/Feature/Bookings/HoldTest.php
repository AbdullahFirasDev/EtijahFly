<?php

use App\Domain\Bookings\BookingService;
use App\Domain\Bookings\BookingStateMachine;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Jobs\ReconcileBooking;
use App\Domain\Bookings\Models\Booking;
use App\Domain\Bookings\Models\BookingPassenger;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Providers\ProviderManager;
use App\Domain\Ticketing\Jobs\GenerateBookingDocuments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    Queue::fake([GenerateBookingDocuments::class]);
    $this->tenant = agency('USD', '5000.00');
    $this->agent = actingAsPartner(agencyUser($this->tenant, 'booking_agent'));
});

it('re-prices and places a hold with PNR and expiry without charging the account', function () {
    $offer = firstOffer();

    $response = createBooking($offer)->assertCreated()
        ->assertJsonPath('data.status', 'on_hold')
        ->assertJsonPath('data.is_test', true)
        ->assertJsonPath('data.price.total', $offer['price']['total']);

    expect($response->json('data.pnr'))->toMatch('/^[A-Z2-9]{6}$/')
        ->and($response->json('data.hold_expires_at'))->not->toBeNull()
        ->and($response->json('data.reference'))->toStartWith('EF-')
        ->and(collect($response->json('data.events'))->pluck('type')->all())->toBe(['created', 'hold_created']);

    expect(wallet($this->tenant)->balance)->toBe('5000.00')
        ->and(LedgerEntry::query()->where('ledger_account_id', wallet($this->tenant)->id)->count())->toBe(1); // funding only
});

it('stores passport numbers encrypted and only returns them masked', function () {
    $response = createBooking(firstOffer(), [passenger('ADT', ['document_number' => 'A12345678'])])->assertCreated();

    expect($response->json('data.passengers.0.document_number_masked'))->toBe('*****5678')
        ->and($response->getContent())->not->toContain('A12345678');

    $raw = DB::table('booking_passengers')->value('document_number');
    expect($raw)->not->toContain('A12345678')
        ->and(BookingPassenger::query()->first()->document_number)->toBe('A12345678');
});

it('replays the same response for a repeated idempotency key and never double-books', function () {
    $offer = firstOffer();
    $key = (string) Str::uuid();
    $passengers = [passenger()];

    $first = createBooking($offer, $passengers, $key)->assertCreated();
    $second = createBooking($offer, $passengers, $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(Booking::query()->count())->toBe(1);
});

it('rejects reuse of an idempotency key with a different payload', function () {
    $offer = firstOffer();
    $key = (string) Str::uuid();
    createBooking($offer, key: $key)->assertCreated();

    createBooking($offer, [passenger('ADT', ['first_name' => 'Omar'])], $key)
        ->assertStatus(422)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
});

it('requires an idempotency key', function () {
    $offer = firstOffer();

    $this->postJson('/api/v1/partner/bookings', ['offer_id' => $offer['id']])->assertStatus(400)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');
});

it('stops the booking when the price changed and reports old and new totals', function () {
    $offer = firstOffer();
    mockScenario('price_change');

    $response = createBooking($offer)->assertStatus(409)->assertJsonPath('code', 'PRICE_CHANGED');
    expect((float) $response->json('details.current_total.amount'))->toBeGreaterThan((float) $offer['price']['total'])
        ->and(Booking::query()->count())->toBe(0);

    // the agent accepts the new price explicitly
    $offer['price']['total'] = $response->json('details.current_total.amount');
    createBooking($offer)->assertCreated()->assertJsonPath('data.status', 'on_hold');
});

it('reports unavailable seats at price check', function () {
    $offer = firstOffer();
    mockScenario('unavailable');

    $this->postJson("/api/v1/partner/flights/offers/{$offer['id']}/price-check")->assertStatus(409)->assertJsonPath('code', 'OFFER_UNAVAILABLE');
    createBooking($offer)->assertStatus(409)->assertJsonPath('code', 'OFFER_UNAVAILABLE');
});

it('refuses expired offers', function () {
    $offer = firstOffer();
    $this->travel(31)->minutes();

    createBooking($offer)->assertStatus(410)->assertJsonPath('code', 'OFFER_EXPIRED');
});

it('blocks bookings that exceed balance plus credit limit', function () {
    $poor = agency('USD', '10.00', '20.00');
    actingAsPartner(agencyUser($poor, 'booking_agent'));

    createBooking(firstOffer())->assertStatus(422)
        ->assertJsonPath('code', 'INSUFFICIENT_CREDIT')
        ->assertJsonPath('details.available.amount', '30.00');
});

it('validates passengers against the searched passenger mix and travel dates', function () {
    $offer = firstOffer(['passengers' => ['adults' => 1, 'children' => 1]]);

    createBooking($offer, [passenger('ADT')])->assertStatus(422)->assertJsonPath('code', 'INVALID_PASSENGERS');
    createBooking($offer, [passenger('ADT'), passenger('CHD', ['date_of_birth' => now()->subYears(30)->toDateString()])])
        ->assertStatus(422)->assertJsonPath('code', 'INVALID_PASSENGERS');
    createBooking($offer, [passenger('ADT', ['document_expiry' => now()->addDays(5)->toDateString()]), passenger('CHD')])
        ->assertStatus(422)->assertJsonPath('code', 'INVALID_PASSENGERS');
    createBooking($offer, [passenger('ADT', ['first_name' => 'علي']), passenger('CHD')])
        ->assertStatus(422)->assertJsonValidationErrors(['passengers.0.first_name']);

    createBooking($offer, [passenger('ADT'), passenger('CHD')])->assertCreated();
});

it('marks the booking failed when the provider rejects the hold', function () {
    $offer = firstOffer();
    mockScenario('hold_rejected');

    createBooking($offer)->assertCreated()
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.failure.code', 'HOLD_REJECTED')
        ->assertJsonPath('data.pnr', null);
});

it('does not assume success when the provider times out, and reconciles with it', function () {
    Queue::fake([GenerateBookingDocuments::class, ReconcileBooking::class]);
    $offer = firstOffer();
    mockScenario('hold_timeout');

    $response = createBooking($offer)->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.pnr', null);
    Queue::assertPushed(ReconcileBooking::class);

    mockScenario('normal');
    (new ReconcileBooking($response->json('data.id')))->handle(app(ProviderManager::class), app(BookingService::class), app(BookingStateMachine::class));

    $booking = Booking::query()->find($response->json('data.id'));
    expect($booking->status)->toBe(BookingStatus::OnHold)->and($booking->pnr)->not->toBeNull();
});
