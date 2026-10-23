<?php

namespace App\Domain\Bookings\Jobs;

use App\Domain\Bookings\BookingService;
use App\Domain\Bookings\BookingStateMachine;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Models\Booking;
use App\Domain\Providers\Exceptions\ProviderException;
use App\Domain\Providers\ProviderManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * After a timeout while creating a hold, ask the provider whether the booking exists
 * (by our client reference) instead of guessing.
 */
class ReconcileBooking implements ShouldQueue
{
    use Queueable;

    public const MAX_ATTEMPTS = 5;

    public function __construct(public int $bookingId, public int $attempt = 1) {}

    public function handle(ProviderManager $providers, BookingService $bookings, BookingStateMachine $states): void
    {
        $booking = Booking::query()->withoutGlobalScopes()->find($this->bookingId);
        if (! $booking || $booking->status !== BookingStatus::Pending || $booking->provider_booking_id) {
            return;
        }

        try {
            $snapshot = $providers->get($booking->provider_code)->findByClientReference($booking->reference);
        } catch (ProviderException $e) {
            $this->retryOrFail($booking, $states, $e->getMessage());

            return;
        }

        if ($snapshot) {
            $bookings->applyHoldResult($booking, $snapshot, null);

            return;
        }

        $this->retryOrFail($booking, $states, 'No booking found at provider');
    }

    private function retryOrFail(Booking $booking, BookingStateMachine $states, string $reason): void
    {
        if ($this->attempt >= self::MAX_ATTEMPTS) {
            $states->transition($booking, BookingStatus::Failed, 'reconciliation_failed', __('bookings.events.reconciliation_failed'), [
                'failure_code' => 'PROVIDER_NO_RECORD',
                'failure_reason' => $reason,
            ], actorType: 'system');

            return;
        }

        self::dispatch($this->bookingId, $this->attempt + 1)->delay(now()->addSeconds(30 * (2 ** $this->attempt)));
    }
}
