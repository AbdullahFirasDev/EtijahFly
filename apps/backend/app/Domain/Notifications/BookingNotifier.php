<?php

namespace App\Domain\Notifications;

use App\Domain\Bookings\Models\Booking;
use App\Domain\Finance\Models\LedgerAccount;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Payments\Models\Payment;
use App\Domain\Shared\Tenancy\TenantScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class BookingNotifier
{
    public function held(Booking $booking): void
    {
        $this->toBooking($booking, 'booking_held', ['expires' => $booking->hold_expires_at?->toDateTimeString()]);
    }

    public function holdExpiring(Booking $booking): void
    {
        $this->toBooking($booking, 'hold_expiring', ['expires' => $booking->hold_expires_at?->toDateTimeString()], mail: true, severity: 'warning');
    }

    public function expired(Booking $booking): void
    {
        $this->toBooking($booking, 'booking_expired', severity: 'warning');
    }

    public function ticketed(Booking $booking): void
    {
        $this->toBooking($booking, 'booking_ticketed', mail: true, severity: 'success');
    }

    public function ticketingFailed(Booking $booking): void
    {
        $this->toBooking($booking, 'ticketing_failed', ['reason' => $booking->failure_reason], mail: true, severity: 'error');
    }

    public function failed(Booking $booking): void
    {
        $this->toBooking($booking, 'booking_failed', ['reason' => $booking->failure_reason], severity: 'error');
    }

    public function cancelled(Booking $booking): void
    {
        $this->toBooking($booking, 'booking_cancelled');
    }

    public function refunded(Booking $booking): void
    {
        $this->toBooking($booking, 'booking_refunded', mail: true, severity: 'success');
    }

    public function paymentReviewed(Payment $payment): void
    {
        $kind = $payment->status === 'approved' ? 'payment_approved' : 'payment_rejected';
        $recipients = $this->tenantUsersWith($payment->tenant_id, 'finance.view');

        Notification::send($recipients, new PlatformNotification(
            $kind,
            ['reference' => $payment->reference, 'amount' => $payment->amount.' '.$payment->currency],
            ['payment_id' => $payment->id],
            $this->portalUrl('/finance/payments'),
            mail: true,
            severity: $payment->status === 'approved' ? 'success' : 'warning',
        ));
    }

    public function lowBalance(LedgerAccount $wallet): void
    {
        if ($wallet->tenant_id === null) {
            return;
        }

        Notification::send($this->tenantUsersWith($wallet->tenant_id, 'finance.view'), new PlatformNotification(
            'low_balance',
            ['available' => $wallet->available()->toDecimal().' '.$wallet->currency],
            url: $this->portalUrl('/finance'),
            mail: true,
            severity: 'warning',
        ));
    }

    /** @param array<string, mixed> $params */
    private function toBooking(Booking $booking, string $kind, array $params = [], bool $mail = false, string $severity = 'info'): void
    {
        $recipients = $this->tenantUsersWith($booking->tenant_id, 'bookings.view_all');
        if ($booking->created_by && ($creator = User::query()->withoutGlobalScope(TenantScope::class)->find($booking->created_by))) {
            $recipients->push($creator);
        }

        Notification::send(
            $recipients->unique('id')->filter(fn (User $u) => $u->status === UserStatus::Active)->values(),
            new PlatformNotification(
                $kind,
                $params + ['reference' => $booking->reference, 'route' => $booking->route()],
                ['booking_id' => $booking->id, 'status' => $booking->status->value],
                $this->portalUrl('/bookings/'.$booking->id),
                $mail,
                $severity,
            ),
        );
    }

    /** @return Collection<int, User> */
    private function tenantUsersWith(int $tenantId, string $permission): Collection
    {
        return User::query()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('status', UserStatus::Active)
            ->permission($permission)
            ->get();
    }

    private function portalUrl(string $path): string
    {
        return rtrim((string) config('platform.portals.partner'), '/').$path;
    }
}
