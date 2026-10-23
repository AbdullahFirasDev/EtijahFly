<?php

namespace App\Domain\Providers\Booking\Data;

enum ProviderBookingStatus: string
{
    case Held = 'held';
    case Confirmed = 'confirmed';
    case Ticketed = 'ticketed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Refunded = 'refunded';
    case Unknown = 'unknown';
}
