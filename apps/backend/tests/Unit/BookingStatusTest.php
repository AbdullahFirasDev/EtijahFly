<?php

use App\Domain\Bookings\Enums\BookingStatus as S;

it('allows only the documented lifecycle transitions', function (S $from, S $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with([
    'pending -> on_hold' => [S::Pending, S::OnHold, true],
    'on_hold -> confirmed' => [S::OnHold, S::Confirmed, true],
    'confirmed -> ticketed' => [S::Confirmed, S::Ticketed, true],
    'ticketed -> refund_pending' => [S::Ticketed, S::RefundPending, true],
    'refund_pending -> refunded' => [S::RefundPending, S::Refunded, true],
    'refund rejected back to ticketed' => [S::RefundPending, S::Ticketed, true],
    'pending -> ticketed (skip)' => [S::Pending, S::Ticketed, false],
    'on_hold -> ticketed (no charge)' => [S::OnHold, S::Ticketed, false],
    'ticketed -> cancelled' => [S::Ticketed, S::Cancelled, false],
    'cancelled is final' => [S::Cancelled, S::OnHold, false],
    'refunded is final' => [S::Refunded, S::Ticketed, false],
    'expired is final' => [S::Expired, S::Confirmed, false],
]);

it('marks charged states', function () {
    expect(S::Confirmed->isCharged())->toBeTrue()
        ->and(S::Ticketed->isCharged())->toBeTrue()
        ->and(S::OnHold->isCharged())->toBeFalse()
        ->and(S::Refunded->isCharged())->toBeFalse();
});
