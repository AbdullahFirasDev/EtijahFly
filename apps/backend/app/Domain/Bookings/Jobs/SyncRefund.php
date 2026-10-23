<?php

namespace App\Domain\Bookings\Jobs;

use App\Domain\Bookings\Enums\RefundStatus;
use App\Domain\Bookings\Models\Refund;
use App\Domain\Bookings\RefundService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncRefund implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $refundId) {}

    public function handle(RefundService $refunds): void
    {
        $refund = Refund::query()->withoutGlobalScopes()->with(['booking' => fn ($q) => $q->withoutGlobalScopes()])->find($this->refundId);
        if ($refund && in_array($refund->status, [RefundStatus::Requested, RefundStatus::Pending], true)) {
            $refunds->submitToProvider($refund);
        }
    }
}
