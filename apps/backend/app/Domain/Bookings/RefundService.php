<?php

namespace App\Domain\Bookings;

use App\Domain\AuditLog\Auditor;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Enums\RefundStatus;
use App\Domain\Bookings\Jobs\SyncRefund;
use App\Domain\Bookings\Models\Booking;
use App\Domain\Bookings\Models\Refund;
use App\Domain\Finance\BookingLedger;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\BookingNotifier;
use App\Domain\Providers\Booking\Data\RefundResult;
use App\Domain\Providers\Exceptions\ProviderException;
use App\Domain\Providers\Exceptions\ProviderRejectedException;
use App\Domain\Providers\ProviderManager;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Shared\Money\Money;
use App\Domain\Ticketing\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * Refunds of ticketed bookings. The agency is only credited once the refund is completed,
 * either confirmed by the provider or recorded manually by platform staff with the
 * airline/BSP confirmation reference (offline refunds).
 */
class RefundService
{
    public function __construct(
        private readonly ProviderManager $providers,
        private readonly BookingStateMachine $states,
        private readonly BookingLedger $ledger,
        private readonly Auditor $auditor,
        private readonly BookingNotifier $notifier,
    ) {}

    public function request(Booking $booking, User $actor, string $reason): Refund
    {
        $refund = DB::transaction(function () use ($booking, $actor, $reason) {
            $locked = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            if ($locked->status !== BookingStatus::Ticketed) {
                throw BusinessRuleException::make('BOOKING_NOT_REFUNDABLE', __('errors.booking_not_refundable'), 409, ['status' => $locked->status->value]);
            }

            $refund = Refund::query()->create([
                'booking_id' => $locked->id,
                'tenant_id' => $locked->tenant_id,
                'status' => RefundStatus::Requested,
                'reason' => mb_substr($reason, 0, 500),
                'requested_by' => $actor->id,
                'currency' => $locked->currency,
                'account_currency' => $locked->account_currency,
            ]);

            $this->states->transition($locked, BookingStatus::RefundPending, 'refund_requested', __('bookings.events.refund_requested', ['reason' => $reason]), data: ['refund_id' => $refund->id], actor: $actor);

            return $refund;
        });

        $this->auditor->record('booking.refund_requested', $booking, new: ['refund_id' => $refund->id, 'reason' => $reason]);

        return $this->submitToProvider($refund, $actor);
    }

    /** Send (or re-send) the refund to the provider. Providers must treat repeats idempotently. */
    public function submitToProvider(Refund $refund, ?User $actor = null): Refund
    {
        $booking = $refund->booking;
        $provider = $this->providers->get($booking->provider_code);

        if (! $provider->capabilities()->refund) {
            return $this->markPending($refund, 'Provider does not support automated refunds; awaiting manual processing');
        }

        try {
            $result = $provider->refund($booking->provider_booking_id);
        } catch (ProviderRejectedException $e) {
            return $this->reject($refund, $e->getMessage(), $actor);
        } catch (ProviderException $e) {
            $this->markPending($refund, $e->getMessage());
            SyncRefund::dispatch($refund->id)->delay(now()->addMinutes(5));

            return $refund->refresh();
        }

        return $this->applyResult($refund, $result, $actor);
    }

    public function applyResult(Refund $refund, RefundResult $result, ?User $actor): Refund
    {
        if ($result->isCompleted()) {
            if (! $result->refundAmount) {
                throw BusinessRuleException::make('INVALID_REFUND_DATA', __('errors.invalid_refund_data'), 502);
            }

            return $this->complete($refund, $result->refundAmount, $result->penalty, 'provider', $result->providerRefundId, null, $actor);
        }

        if ($result->isPending()) {
            $refund->forceFill(['provider_refund_id' => $result->providerRefundId])->save();
            $this->markPending($refund, $result->message ?? 'Refund pending at provider');
            SyncRefund::dispatch($refund->id)->delay(now()->addMinutes(15));

            return $refund->refresh();
        }

        return $this->reject($refund, $result->message ?? 'Refund rejected by provider', $actor);
    }

    /** Offline refund confirmed by the airline / BSP, recorded by platform staff. */
    public function completeManually(Refund $refund, User $staff, Money $providerRefund, ?Money $penalty, string $confirmationReference): Refund
    {
        if (! in_array($refund->status, [RefundStatus::Requested, RefundStatus::Pending], true)) {
            throw BusinessRuleException::make('REFUND_NOT_OPEN', __('errors.refund_not_open'), 409);
        }
        if ($providerRefund->currency !== $refund->currency) {
            throw BusinessRuleException::make('CURRENCY_MISMATCH', __('errors.currency_mismatch'), 422);
        }
        if ($providerRefund->greaterThan($refund->booking->supplierTotal())) {
            throw BusinessRuleException::make('REFUND_EXCEEDS_FARE', __('errors.refund_exceeds_fare'), 422);
        }

        return $this->complete($refund, $providerRefund, $penalty, 'manual', null, $confirmationReference, $staff);
    }

    public function rejectManually(Refund $refund, User $staff, string $reason): Refund
    {
        if (! in_array($refund->status, [RefundStatus::Requested, RefundStatus::Pending], true)) {
            throw BusinessRuleException::make('REFUND_NOT_OPEN', __('errors.refund_not_open'), 409);
        }

        return $this->reject($refund, $reason, $staff);
    }

    private function complete(Refund $refund, Money $providerRefund, ?Money $penalty, string $source, ?string $providerRefundId, ?string $confirmation, ?User $actor): Refund
    {
        $refund = DB::transaction(function () use ($refund, $providerRefund, $penalty, $source, $providerRefundId, $confirmation, $actor) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($refund->booking_id);
            $refund = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($refund->status === RefundStatus::Completed) {
                return $refund;
            }

            $posting = $this->ledger->refund($booking, $refund, $providerRefund, $actor);

            $refund->forceFill([
                'status' => RefundStatus::Completed,
                'source' => $source,
                'processed_by' => $actor?->id,
                'supplier_refund_amount' => $providerRefund->toDecimal(),
                'penalty_amount' => $penalty?->toDecimal(),
                'refund_fee' => $posting['fee']->toDecimal(),
                'agency_refund_amount' => $posting['agency_credit']->toDecimal(),
                'commission_clawback' => $posting['clawback']->toDecimal(),
                'provider_refund_id' => $providerRefundId ?? $refund->provider_refund_id,
                'confirmation_reference' => $confirmation,
                'ledger_transaction_id' => $posting['transaction']?->id,
                'completed_at' => now(),
            ])->save();

            Ticket::query()->where('booking_id', $booking->id)->update(['status' => 'refunded']);

            $this->states->transition($booking, BookingStatus::Refunded, 'refunded', __('bookings.events.refunded', [
                'amount' => $posting['agency_credit']->toDecimal().' '.$posting['agency_credit']->currency,
            ]), data: ['refund_id' => $refund->id, 'source' => $source, 'confirmation_reference' => $confirmation], actor: $actor, actorType: $actor ? 'user' : 'provider');

            return $refund;
        });

        $this->auditor->record('booking.refunded', $refund->booking, new: [
            'refund_id' => $refund->id, 'source' => $source, 'agency_refund_amount' => $refund->agency_refund_amount,
        ], actor: $actor, actorType: $actor ? 'user' : 'provider');
        $this->notifier->refunded($refund->booking);

        return $refund;
    }

    private function reject(Refund $refund, string $reason, ?User $actor): Refund
    {
        DB::transaction(function () use ($refund, $reason, $actor) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($refund->booking_id);
            $refund->forceFill(['status' => RefundStatus::Rejected, 'failure_reason' => mb_substr($reason, 0, 1000), 'processed_by' => $actor?->id])->save();

            if ($booking->status === BookingStatus::RefundPending) {
                $this->states->transition($booking, BookingStatus::Ticketed, 'refund_rejected', __('bookings.events.refund_rejected', ['reason' => $reason]), data: ['refund_id' => $refund->id], actor: $actor, actorType: $actor ? 'user' : 'provider');
            }
        });

        $this->auditor->record('booking.refund_rejected', $refund->booking, new: ['refund_id' => $refund->id, 'reason' => $reason], actor: $actor);

        return $refund->refresh();
    }

    private function markPending(Refund $refund, string $message): Refund
    {
        if ($refund->status !== RefundStatus::Pending) {
            $refund->forceFill(['status' => RefundStatus::Pending])->save();
            $this->states->record($refund->booking, 'refund_pending', __('bookings.events.refund_pending'), ['message' => $message], actorType: 'provider');
        }

        return $refund;
    }
}
