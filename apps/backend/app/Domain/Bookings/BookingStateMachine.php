<?php

namespace App\Domain\Bookings;

use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Models\Booking;
use App\Domain\Bookings\Models\BookingEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Exceptions\BusinessRuleException;

/**
 * The only place booking statuses change. Every change is validated against
 * BookingStatus::allowedTransitions() and written to the immutable timeline.
 */
class BookingStateMachine
{
    /**
     * @param  array<string, mixed>  $attributes  extra booking columns to persist with the change
     * @param  array<string, mixed>  $data  event payload
     */
    public function transition(
        Booking $booking,
        BookingStatus $to,
        string $eventType,
        string $message,
        array $attributes = [],
        array $data = [],
        ?User $actor = null,
        string $actorType = 'user',
    ): Booking {
        $from = $booking->status;

        if (! $from->canTransitionTo($to)) {
            throw BusinessRuleException::make('INVALID_STATUS_TRANSITION', __('errors.invalid_transition', [
                'from' => $from->value,
                'to' => $to->value,
            ]), 409, ['from' => $from->value, 'to' => $to->value]);
        }

        $booking->forceFill($attributes + ['status' => $to])->save();

        $this->record($booking, $eventType, $message, $data, $actor, $actorType, $from, $to);

        return $booking;
    }

    /** @param array<string, mixed> $data */
    public function record(
        Booking $booking,
        string $eventType,
        string $message,
        array $data = [],
        ?User $actor = null,
        string $actorType = 'user',
        ?BookingStatus $from = null,
        ?BookingStatus $to = null,
    ): BookingEvent {
        return BookingEvent::query()->create([
            'booking_id' => $booking->id,
            'tenant_id' => $booking->tenant_id,
            'type' => $eventType,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'message' => mb_substr($message, 0, 1000),
            'data' => $data ?: null,
            'actor_id' => $actor?->id,
            'actor_type' => $actor ? $actorType : ($actorType === 'user' ? 'system' : $actorType),
        ]);
    }
}
