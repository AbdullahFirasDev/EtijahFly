<?php

namespace App\Domain\Ticketing\Jobs;

use App\Domain\Bookings\Models\Booking;
use App\Domain\Ticketing\TicketingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class VerifyTicketing implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $bookingId, public int $attempt = 1) {}

    public function handle(TicketingService $ticketing): void
    {
        $booking = Booking::query()->withoutGlobalScopes()->find($this->bookingId);
        if ($booking) {
            $ticketing->verify($booking, $this->attempt);
        }
    }
}
