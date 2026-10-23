<?php

namespace App\Domain\Ticketing\Jobs;

use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Models\Booking;
use App\Domain\Ticketing\DocumentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateBookingDocuments implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $bookingId) {}

    public function handle(DocumentService $documents): void
    {
        $booking = Booking::query()->withoutGlobalScopes()->find($this->bookingId);
        if (! $booking) {
            return;
        }

        $documents->generateConfirmation($booking);
        if ($booking->status === BookingStatus::Ticketed) {
            $documents->generateETicket($booking);
        }
    }
}
