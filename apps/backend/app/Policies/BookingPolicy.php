<?php

namespace App\Policies;

use App\Domain\Bookings\Models\Booking;
use App\Domain\Identity\Models\User;

/**
 * Record-level rules for partner users. Tenant isolation itself is enforced earlier (tenant
 * scope -> 404); this policy narrows access inside a tenant: without `bookings.view_all`
 * users only see and act on bookings they created.
 */
class BookingPolicy
{
    public function view(User $user, Booking $booking): bool
    {
        return $this->sameTenant($user, $booking)
            && ($user->can('bookings.view_all') || ($user->can('bookings.view') && $booking->created_by === $user->id));
    }

    public function issue(User $user, Booking $booking): bool
    {
        return $this->view($user, $booking) && $user->can('bookings.issue');
    }

    public function cancel(User $user, Booking $booking): bool
    {
        return $this->view($user, $booking) && $user->can('bookings.cancel');
    }

    public function refund(User $user, Booking $booking): bool
    {
        return $this->view($user, $booking) && $user->can('bookings.refund');
    }

    public function documents(User $user, Booking $booking): bool
    {
        return $this->view($user, $booking) && $user->can('documents.view');
    }

    private function sameTenant(User $user, Booking $booking): bool
    {
        return $user->tenant_id !== null && $user->tenant_id === $booking->tenant_id;
    }
}
