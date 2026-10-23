<?php

namespace App\Domain\Bookings\Enums;

/**
 * Booking lifecycle. Transitions are enforced by BookingStateMachine.
 *
 *  pending ──► on_hold ──► confirmed ──► ticketed ──► refund_pending ──► refunded
 *     │           │            │                            │
 *     ▼           ▼            ▼                            ▼
 *   failed    expired /     cancelled                    ticketed (refund rejected)
 *             cancelled
 *
 * confirmed = agency account charged; ticketed = provider confirmed e-ticket numbers.
 */
enum BookingStatus: string
{
    case Pending = 'pending';
    case OnHold = 'on_hold';
    case Confirmed = 'confirmed';
    case Ticketed = 'ticketed';
    case Failed = 'failed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case RefundPending = 'refund_pending';
    case Refunded = 'refunded';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::OnHold, self::Confirmed, self::Failed, self::Cancelled],
            self::OnHold => [self::Confirmed, self::Expired, self::Cancelled, self::Failed],
            // Confirmed -> OnHold: ticketing definitively failed, charge reversed, hold still valid.
            self::Confirmed => [self::Ticketed, self::OnHold, self::Failed, self::Cancelled],
            self::Ticketed => [self::RefundPending],
            self::RefundPending => [self::Refunded, self::Ticketed],
            self::Failed, self::Expired, self::Cancelled, self::Refunded => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** True when the agency account currently carries a charge for this booking. */
    public function isCharged(): bool
    {
        return in_array($this, [self::Confirmed, self::Ticketed, self::RefundPending], true);
    }

    public function label(): string
    {
        return __('statuses.booking.'.$this->value);
    }
}
