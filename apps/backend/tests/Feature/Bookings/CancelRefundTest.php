<?php

use App\Domain\Bookings\BookingStateMachine;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\HoldExpiryService;
use App\Domain\Bookings\Jobs\SyncRefund;
use App\Domain\Bookings\Models\Booking;
use App\Domain\Bookings\Models\Refund;
use App\Domain\Bookings\RefundService;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\LedgerTransaction;
use App\Domain\Providers\Booking\Data\Fare;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Shared\Money\Money;
use App\Domain\Ticketing\Jobs\GenerateBookingDocuments;
use App\Domain\Ticketing\Jobs\GenerateInvoiceDocument;
use App\Domain\Ticketing\Models\Ticket;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([GenerateBookingDocuments::class, GenerateInvoiceDocument::class, SyncRefund::class]);
    $this->tenant = agency('USD', '5000.00');
    $this->agent = actingAsPartner(agencyUser($this->tenant, 'booking_agent'));
});

function ticketedBooking(): array
{
    $booking = createBooking(firstOffer())->assertCreated()->json('data');

    return issueBooking($booking['id'])->assertOk()->assertJsonPath('data.status', 'ticketed')->json('data');
}

it('cancels a hold at the provider without any money movement', function () {
    $booking = createBooking(firstOffer())->json('data');

    $this->postJson("/api/v1/partner/bookings/{$booking['id']}/cancel", ['reason' => 'Customer changed plans'])->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancellation_reason', 'Customer changed plans');

    expect(LedgerTransaction::query()->whereIn('type', ['booking_charge', 'reversal'])->count())->toBe(0);
});

it('keeps the booking unchanged when the provider cannot cancel', function () {
    $booking = createBooking(firstOffer())->json('data');
    mockScenario('cancel_failed');

    $this->postJson("/api/v1/partner/bookings/{$booking['id']}/cancel", ['reason' => 'x'])->assertStatus(502)->assertJsonPath('code', 'CANCEL_FAILED');
    expect(Booking::query()->find($booking['id'])->status)->toBe(BookingStatus::OnHold);
});

it('requires a refund instead of a cancellation once ticketed', function () {
    $booking = ticketedBooking();

    $this->postJson("/api/v1/partner/bookings/{$booking['id']}/cancel", ['reason' => 'x'])->assertStatus(409)->assertJsonPath('code', 'USE_REFUND');
});

it('refunds a ticketed booking: credits the provider refund, keeps the markup and claws back commission', function () {
    $booking = ticketedBooking();
    $afterCharge = wallet($this->tenant)->balanceMoney();

    $response = $this->withHeader('Idempotency-Key', 'refund-'.$booking['id'])
        ->postJson("/api/v1/partner/bookings/{$booking['id']}/refund", ['reason' => 'Visa refused'])->assertOk()
        ->assertJsonPath('refund.status', 'completed')
        ->assertJsonPath('booking.status', 'refunded');

    $model = Booking::query()->find($booking['id']);
    $refund = Refund::query()->sole();
    $fare = Fare::of((string) $model->supplier_base, (string) $model->supplier_taxes, 'USD');
    $penalty = $model->refundable ? $fare->base->percent(10) : $fare->base;
    $providerRefund = $fare->total()->minus($penalty);

    expect($refund->supplier_refund_amount)->toBe($providerRefund->toDecimal())
        ->and($refund->agency_refund_amount)->toBe($providerRefund->toDecimal())
        ->and($refund->commission_clawback)->toBe((string) $model->commission_credited);

    $expectedBalance = $afterCharge->plus($providerRefund)->minus(Money::of((string) $model->commission_credited, 'USD'));
    expect(wallet($this->tenant)->balance)->toBe($expectedBalance->toDecimal())
        ->and(Ticket::query()->where('status', 'refunded')->count())->toBe(1)
        ->and(Invoice::query()->where('type', 'credit_note')->count())->toBe(1)
        ->and($response->json('refund'))->not->toHaveKey('supplier_refund_amount'); // internal figure hidden from agencies
    assertLedgerBalanced();
});

it('keeps refunds pending until the provider confirms, then completes once', function () {
    $booking = ticketedBooking();
    mockScenario('refund_pending');

    $this->withHeader('Idempotency-Key', 'refund-key-1')->postJson("/api/v1/partner/bookings/{$booking['id']}/refund", ['reason' => 'x'])->assertOk()
        ->assertJsonPath('refund.status', 'pending')
        ->assertJsonPath('booking.status', 'refund_pending');
    expect(LedgerTransaction::query()->where('type', 'refund')->count())->toBe(0);
    Queue::assertPushed(SyncRefund::class);

    mockScenario('normal');
    (new SyncRefund(Refund::query()->sole()->id))->handle(app(RefundService::class));
    (new SyncRefund(Refund::query()->sole()->id))->handle(app(RefundService::class));

    expect(Refund::query()->sole()->status->value)->toBe('completed')
        ->and(Booking::query()->find($booking['id'])->status)->toBe(BookingStatus::Refunded)
        ->and(LedgerTransaction::query()->where('type', 'refund')->count())->toBe(1);
});

it('lets platform staff record an offline refund with a confirmation reference', function () {
    $booking = ticketedBooking();
    mockScenario('refund_pending');
    $this->withHeader('Idempotency-Key', 'refund-key-2')->postJson("/api/v1/partner/bookings/{$booking['id']}/refund", ['reason' => 'x'])->assertOk();
    $refund = Refund::query()->sole();

    actingAsAdmin(platformUser('platform_staff'));
    $this->postJson("/api/v1/admin/refunds/{$refund->id}/complete", ['supplier_refund_amount' => '100.00', 'confirmation_reference' => 'BSP-RF-778812'])->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.source', 'manual')
        ->assertJsonPath('data.confirmation_reference', 'BSP-RF-778812');

    $this->postJson("/api/v1/admin/refunds/{$refund->id}/complete", ['supplier_refund_amount' => '100.00', 'confirmation_reference' => 'again'])
        ->assertStatus(409)->assertJsonPath('code', 'REFUND_NOT_OPEN');
    assertLedgerBalanced();
});

it('cannot refund more than the supplier fare', function () {
    $booking = ticketedBooking();
    mockScenario('refund_pending');
    $this->withHeader('Idempotency-Key', 'refund-key-3')->postJson("/api/v1/partner/bookings/{$booking['id']}/refund", ['reason' => 'x']);
    actingAsAdmin(platformUser('platform_staff'));

    $this->postJson('/api/v1/admin/refunds/'.Refund::query()->sole()->id.'/complete', ['supplier_refund_amount' => '999999.00', 'confirmation_reference' => 'X'])
        ->assertStatus(422)->assertJsonPath('code', 'REFUND_EXCEEDS_FARE');
});

it('expires lapsed holds once the deadline has passed', function () {
    $booking = createBooking(firstOffer())->json('data');
    expect(app(HoldExpiryService::class)->expireDue())->toBe(0);

    $this->travel(3)->hours();

    expect(app(HoldExpiryService::class)->expireDue())->toBe(1);
    expect(Booking::query()->find($booking['id'])->status)->toBe(BookingStatus::Expired);
    $this->travelBack();
});

it('keeps a hold the provider still reports as valid and adopts the provider deadline', function () {
    $booking = createBooking(firstOffer())->json('data');
    Booking::query()->whereKey($booking['id'])->update(['hold_expires_at' => now()->subMinute()]);

    expect(app(HoldExpiryService::class)->expireDue())->toBe(0);
    $model = Booking::query()->find($booking['id']);
    expect($model->status)->toBe(BookingStatus::OnHold)
        ->and($model->hold_expires_at->isFuture())->toBeTrue()
        ->and($model->events()->pluck('type'))->toContain('hold_extended');
});

it('treats our deadline as authoritative when the provider cannot be reached', function () {
    $booking = createBooking(firstOffer())->json('data');
    Booking::query()->whereKey($booking['id'])->update(['hold_expires_at' => now()->subMinute(), 'provider_booking_id' => 'UNREACHABLE']);

    expect(app(HoldExpiryService::class)->expireDue())->toBe(1);
    expect(Booking::query()->find($booking['id'])->status)->toBe(BookingStatus::Expired);
});

it('rejects invalid status transitions at the state machine', function () {
    $booking = Booking::factory()->for($this->tenant)->create(['status' => BookingStatus::OnHold]);

    app(BookingStateMachine::class)->transition($booking, BookingStatus::Ticketed, 'x', 'skip charge');
})->throws(BusinessRuleException::class);
