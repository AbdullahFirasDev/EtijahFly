<?php

use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Enums\TicketingStatus;
use App\Domain\Bookings\Models\Booking;
use App\Domain\Commissions\Models\ExchangeRate;
use App\Domain\Commissions\Models\PricingRule;
use App\Domain\Finance\AccountService;
use App\Domain\Finance\Data\LedgerLine;
use App\Domain\Finance\LedgerService;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\LedgerTransaction;
use App\Domain\Shared\Money\Money;
use App\Domain\Ticketing\Jobs\GenerateBookingDocuments;
use App\Domain\Ticketing\Jobs\GenerateInvoiceDocument;
use App\Domain\Ticketing\Jobs\VerifyTicketing;
use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\TicketingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([GenerateBookingDocuments::class, GenerateInvoiceDocument::class]);
    PricingRule::query()->create(['name' => 'Markup 5%', 'type' => 'markup', 'calculation' => 'percent', 'value' => '5', 'basis' => 'supplier_total']);
    PricingRule::query()->create(['name' => 'Commission 2%', 'type' => 'commission', 'calculation' => 'percent', 'value' => '2', 'basis' => 'base_fare']);
    $this->tenant = agency('USD', '5000.00');
    $this->agent = actingAsPartner(agencyUser($this->tenant, 'booking_agent'));
});

function heldBooking(): array
{
    return createBooking(firstOffer())->assertCreated()->json('data');
}

it('charges the agency account and issues e-tickets confirmed by the provider', function () {
    $booking = heldBooking();
    $before = wallet($this->tenant)->balanceMoney();

    $response = issueBooking($booking['id'])->assertOk()
        ->assertJsonPath('data.status', 'ticketed')
        ->assertJsonPath('data.ticketing_status', 'issued');

    $tickets = Ticket::query()->where('booking_id', $booking['id'])->get();
    expect($tickets)->toHaveCount(1)
        ->and($tickets[0]->ticket_number)->toMatch('/^000\d{10}$/') // unassigned test prefix
        ->and($tickets[0]->is_test)->toBeTrue()
        ->and($response->json('data.passengers.0.ticket_number'))->toBe($tickets[0]->ticket_number);

    // Wallet impact = sale total - commission (agency net)
    $after = wallet($this->tenant)->balanceMoney();
    expect($before->minus($after)->toDecimal())->toBe($booking['price']['agency_net']);

    $charge = LedgerTransaction::query()->where('type', 'booking_charge')->sole();
    expect($charge->entries()->count())->toBe(5);
    expect(Invoice::query()->where('booking_id', $booking['id'])->sole()->total)->toBe($booking['price']['agency_net']);
    assertLedgerBalanced();

    Queue::assertPushed(GenerateBookingDocuments::class);
    expect(collect($response->json('data.events'))->pluck('type')->all())->toContain('charged', 'ticketed');
});

it('never charges twice for repeated or concurrent issue requests', function () {
    $booking = heldBooking();

    issueBooking($booking['id'])->assertOk();
    issueBooking($booking['id'])->assertOk()->assertJsonPath('data.status', 'ticketed');
    app(TicketingService::class)->issue(Booking::query()->find($booking['id']), $this->agent);

    expect(LedgerTransaction::query()->where('type', 'booking_charge')->count())->toBe(1)
        ->and(Ticket::query()->count())->toBe(1);
});

it('reverses the charge and keeps the hold when ticketing definitively fails', function () {
    $booking = heldBooking();
    $before = wallet($this->tenant)->balance;
    mockScenario('ticketing_failed');

    issueBooking($booking['id'])->assertOk()
        ->assertJsonPath('data.status', 'on_hold')
        ->assertJsonPath('data.ticketing_status', 'failed')
        ->assertJsonPath('data.failure.code', 'TICKETING_DECLINED');

    expect(wallet($this->tenant)->balance)->toBe($before)
        ->and(LedgerTransaction::query()->where('type', 'reversal')->count())->toBe(1)
        ->and(Invoice::query()->where('type', 'credit_note')->count())->toBe(1)
        ->and(Ticket::query()->count())->toBe(0);
    assertLedgerBalanced();
});

it('keeps the booking confirmed (not ticketed) when the outcome is unknown, then verifies it', function () {
    Queue::fake([GenerateBookingDocuments::class, GenerateInvoiceDocument::class, VerifyTicketing::class]);
    $booking = heldBooking();
    mockScenario('ticketing_timeout');

    issueBooking($booking['id'])->assertOk()
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.ticketing_status', 'pending_verification');
    expect(Ticket::query()->count())->toBe(0);
    Queue::assertPushed(VerifyTicketing::class);

    // the provider did ticket: verification picks the tickets up
    $verified = app(TicketingService::class)->verify(Booking::query()->find($booking['id']), 1);
    expect($verified->status)->toBe(BookingStatus::Ticketed)
        ->and(Ticket::query()->count())->toBe(1);
});

it('flags the booking for manual follow-up after exhausting verification attempts', function () {
    Queue::fake([GenerateBookingDocuments::class, GenerateInvoiceDocument::class, VerifyTicketing::class]);
    $booking = heldBooking();
    mockScenario('ticketing_pending');
    issueBooking($booking['id'])->assertOk()->assertJsonPath('data.ticketing_status', 'pending_verification');

    // The provider keeps failing to answer the status query.
    $model = Booking::query()->find($booking['id']);
    $model->forceFill(['provider_booking_id' => 'DOES-NOT-EXIST'])->save();

    $result = app(TicketingService::class)->verify($model, (int) config('platform.bookings.ticketing_verification_attempts'));
    expect($result->ticketing_status)->toBe(TicketingStatus::NeedsAttention)
        ->and($result->status)->toBe(BookingStatus::Confirmed);
});

it('blocks issuing an expired hold', function () {
    $booking = heldBooking();
    Booking::query()->whereKey($booking['id'])->update(['hold_expires_at' => now()->subMinute()]);

    issueBooking($booking['id'])->assertStatus(409)->assertJsonPath('code', 'HOLD_EXPIRED');
    expect(LedgerTransaction::query()->where('type', 'booking_charge')->count())->toBe(0);
});

it('blocks issuing when the credit limit was reduced after the hold', function () {
    $booking = heldBooking();
    $wallet = wallet($this->tenant);
    DB::transaction(fn () => app(LedgerService::class)->post('adjustment', [
        LedgerLine::debit($wallet, $wallet->balanceMoney()),
        LedgerLine::credit(app(AccountService::class)->system('ADJUSTMENTS', 'USD'), $wallet->balanceMoney()),
    ], 'drain', $this->tenant->id));

    issueBooking($booking['id'])->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_CREDIT');
    expect(Booking::query()->find($booking['id'])->status)->toBe(BookingStatus::OnHold);
});

it('converts to the agency currency at the recorded exchange rate', function () {
    ExchangeRate::query()->create(['base_currency' => 'USD', 'quote_currency' => 'IQD', 'rate' => '1310', 'effective_at' => now()->subHour()]);
    $iqd = agency('IQD', '5000000.00');
    actingAsPartner(agencyUser($iqd, 'booking_agent'));

    $booking = heldBooking();
    $response = issueBooking($booking['id'])->assertOk()->assertJsonPath('data.status', 'ticketed');

    $expected = Money::of($booking['price']['total'], 'USD')->multipliedBy('1310')->toDecimal();
    expect($response->json('data.settlement.account_currency'))->toBe('IQD')
        ->and($response->json('data.settlement.fx_rate'))->toBe('1310.000000')
        ->and($response->json('data.settlement.charged_amount'))->toBe($expected);
    assertLedgerBalanced();
});

it('refuses to charge when no exchange rate exists', function () {
    $iqd = agency('IQD', '5000000.00');
    actingAsPartner(agencyUser($iqd, 'booking_agent'));

    createBooking(firstOffer())->assertStatus(422)->assertJsonPath('code', 'EXCHANGE_RATE_MISSING');
});
