<?php

namespace App\Domain\Bookings\Models;

use App\Domain\Bookings\Enums\RefundStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $booking_id
 * @property int $tenant_id
 * @property RefundStatus $status
 * @property string|null $reason
 * @property int|null $requested_by
 * @property int|null $processed_by
 * @property string|null $source
 * @property string $currency
 * @property string|null $supplier_refund_amount
 * @property string|null $penalty_amount
 * @property string $refund_fee
 * @property string $account_currency
 * @property string|null $agency_refund_amount
 * @property string|null $commission_clawback
 * @property string|null $provider_refund_id
 * @property string|null $confirmation_reference
 * @property int|null $ledger_transaction_id
 * @property string|null $failure_reason
 * @property Carbon|null $completed_at
 * @property-read Booking $booking
 */
class Refund extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'supplier_refund_amount' => 'decimal:2',
            'penalty_amount' => 'decimal:2',
            'refund_fee' => 'decimal:2',
            'agency_refund_amount' => 'decimal:2',
            'commission_clawback' => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by')->withoutGlobalScopes();
    }
}
