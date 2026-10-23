<?php

namespace App\Domain\Bookings;

use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Models\Booking;
use App\Domain\Notifications\BookingNotifier;
use App\Domain\Providers\Booking\Data\ProviderBookingStatus;
use App\Domain\Providers\Exceptions\ProviderException;
use App\Domain\Providers\ProviderManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Scheduled every minute: expires lapsed holds and warns agencies about upcoming expiries. */
class HoldExpiryService
{
    public function __construct(
        private readonly ProviderManager $providers,
        private readonly BookingStateMachine $states,
        private readonly BookingNotifier $notifier,
    ) {}

    public function expireDue(): int
    {
        $expired = 0;

        Booking::query()->withoutGlobalScopes()
            ->where('status', BookingStatus::OnHold)
            ->where('hold_expires_at', '<=', now())
            ->orderBy('hold_expires_at')
            ->limit(200)
            ->get()
            ->each(function (Booking $booking) use (&$expired) {
                if ($this->expire($booking)) {
                    $expired++;
                }
            });

        return $expired;
    }

    public function warnUpcoming(): int
    {
        $minutes = (int) config('platform.bookings.hold_expiry_warning_minutes');
        $warned = 0;

        Booking::query()->withoutGlobalScopes()
            ->where('status', BookingStatus::OnHold)
            ->whereBetween('hold_expires_at', [now(), now()->addMinutes($minutes)])
            ->get()
            ->each(function (Booking $booking) use (&$warned) {
                if (Cache::add("hold_warned:{$booking->id}", true, now()->addDay())) {
                    $this->notifier->holdExpiring($booking);
                    $warned++;
                }
            });

        return $warned;
    }

    private function expire(Booking $booking): bool
    {
        // Confirm with the provider when possible: holds may have been extended there.
        $provider = $this->providers->get($booking->provider_code);
        if ($booking->provider_booking_id && $provider->capabilities()->retrieve) {
            try {
                $snapshot = $provider->retrieveBooking($booking->provider_booking_id);
                if ($snapshot->status === ProviderBookingStatus::Held && $snapshot->holdExpiresAt && Carbon::parse($snapshot->holdExpiresAt)->isFuture()) {
                    $booking->forceFill(['hold_expires_at' => Carbon::parse($snapshot->holdExpiresAt)])->save();
                    $this->states->record($booking, 'hold_extended', __('bookings.events.hold_extended'), ['expires_at' => $snapshot->holdExpiresAt], actorType: 'provider');

                    return false;
                }
            } catch (ProviderException) {
                // Provider unreachable: our deadline is authoritative for the agency.
            }
        }

        return DB::transaction(function () use ($booking) {
            $locked = Booking::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($booking->id);
            if ($locked->status !== BookingStatus::OnHold || ! $locked->isHoldExpired()) {
                return false;
            }

            $this->states->transition($locked, BookingStatus::Expired, 'hold_expired', __('bookings.events.hold_expired'), actorType: 'system');
            $this->notifier->expired($locked);

            return true;
        });
    }
}
