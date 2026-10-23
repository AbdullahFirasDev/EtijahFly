<?php

namespace App\Domain\Reports;

use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Models\Booking;
use App\Domain\Finance\Models\LedgerAccount;
use App\Domain\Payments\Models\Payment;
use App\Domain\Shared\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregated reporting. "Sales" are bookings that were charged and not reversed:
 * ticketed, refund_pending and refunded (refunds are reported separately).
 * Amounts are grouped by currency - never summed across currencies.
 */
class ReportService
{
    private const SOLD = ['ticketed', 'refund_pending', 'refunded'];

    /** @return array<string, mixed> */
    public function summary(?int $tenantId, Carbon $from, Carbon $to, bool $includeInternal): array
    {
        $base = fn () => $this->bookings($tenantId)->whereBetween('bookings.created_at', [$from, $to]);

        $byStatus = $base()->select('status', DB::raw('count(*) as count'))->groupBy('status')->pluck('count', 'status')->map(fn ($v) => (int) $v);

        $salesColumns = [
            'currency',
            DB::raw('count(*) as bookings'),
            DB::raw('sum(sale_total) as sales'),
            DB::raw('sum(commission_amount) as commission'),
            DB::raw('sum(agency_net) as agency_net'),
        ];
        if ($includeInternal) {
            array_push($salesColumns,
                DB::raw('sum(supplier_total) as supplier_cost'),
                DB::raw('sum(markup_amount) as markup'),
                DB::raw('sum(platform_profit) as profit'),
            );
        }

        // Aggregates are plain rows (toBase), not Booking models.
        $sales = $base()->whereIn('status', self::SOLD)->select($salesColumns)->groupBy('currency')->toBase()->get()
            ->map(fn (object $row) => collect((array) $row)->map(fn ($v, $k) => $k === 'currency' ? $v : ($k === 'bookings' ? (int) $v : number_format((float) $v, 2, '.', '')))->all())
            ->values();

        $refunds = $base()->where('status', BookingStatus::Refunded->value)->count();

        $byAirline = $base()->whereIn('status', self::SOLD)
            ->select(DB::raw("itinerary->0->'segments'->0->>'marketing_carrier' as airline"), 'currency', DB::raw('count(*) as bookings'), DB::raw('sum(sale_total) as sales'))
            ->groupBy('airline', 'currency')
            ->orderByDesc('bookings')
            ->limit(10)
            ->toBase()
            ->get()
            ->map(fn (object $r) => ['airline' => $r->airline, 'currency' => $r->currency, 'bookings' => (int) $r->bookings, 'sales' => number_format((float) $r->sales, 2, '.', '')]);

        $daily = $base()->whereIn('status', self::SOLD)
            ->select(DB::raw('date(bookings.created_at) as day'), 'currency', DB::raw('count(*) as bookings'), DB::raw('sum(sale_total) as sales'))
            ->groupBy('day', 'currency')
            ->orderBy('day')
            ->toBase()
            ->get()
            ->map(fn (object $r) => ['day' => (string) $r->day, 'currency' => $r->currency, 'bookings' => (int) $r->bookings, 'sales' => number_format((float) $r->sales, 2, '.', '')]);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'bookings_by_status' => $byStatus,
            'sales_by_currency' => $sales,
            'refunded_bookings' => $refunds,
            'top_airlines' => $byAirline,
            'daily' => $daily,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function commissions(?int $tenantId, Carbon $from, Carbon $to): array
    {
        return $this->bookings($tenantId)
            ->whereBetween('bookings.created_at', [$from, $to])
            ->whereIn('status', self::SOLD)
            ->where('commission_amount', '>', 0)
            ->orderByDesc('bookings.created_at')
            ->limit(500)
            ->get(['id', 'tenant_id', 'reference', 'status', 'currency', 'sale_total', 'commission_amount', 'account_currency', 'commission_credited', 'created_at'])
            ->map(fn (Booking $b) => [
                'booking_id' => $b->id,
                'reference' => $b->reference,
                'status' => $b->status->value,
                'currency' => $b->currency,
                'sale_total' => (string) $b->sale_total,
                'commission' => (string) $b->commission_amount,
                'account_currency' => $b->account_currency,
                'commission_credited' => $b->commission_credited !== null ? (string) $b->commission_credited : null,
                'clawed_back' => $b->status === BookingStatus::Refunded,
                'date' => $b->created_at->toIso8601String(),
            ])->all();
    }

    /**
     * Agency balances: outstanding (negative balance = owed to the platform) and credit usage.
     *
     * @return list<array<string, mixed>>
     */
    public function receivables(): array
    {
        return LedgerAccount::query()->withoutGlobalScope(TenantScope::class)
            ->where('code', LedgerAccount::AGENCY_WALLET)
            ->with('tenant:id,name,status')
            ->orderBy('balance')
            ->get()
            ->map(fn (LedgerAccount $a) => [
                'account_id' => $a->id,
                'tenant_id' => $a->tenant_id,
                'tenant' => $a->tenant?->name,
                'currency' => $a->currency,
                'balance' => (string) $a->balance,
                'credit_limit' => (string) $a->credit_limit,
                'available' => $a->available()->toDecimal(),
                'outstanding' => $a->outstanding()->toDecimal(),
                'status' => $a->status,
            ])->all();
    }

    /** @return array<string, mixed> */
    public function platformDashboard(): array
    {
        $today = now()->startOfDay();
        $month = now()->startOfMonth();

        return [
            'tenants' => [
                'active' => DB::table('tenants')->whereNull('deleted_at')->where('status', 'active')->count(),
                'suspended' => DB::table('tenants')->whereNull('deleted_at')->where('status', 'suspended')->count(),
            ],
            'bookings_today' => $this->bookings(null)->where('created_at', '>=', $today)->count(),
            'on_hold' => $this->bookings(null)->where('status', BookingStatus::OnHold->value)->count(),
            'needs_attention' => $this->bookings(null)->whereIn('ticketing_status', ['pending_verification', 'needs_attention'])->count(),
            'pending_payments' => Payment::query()->withoutGlobalScope(TenantScope::class)->where('status', 'pending')->count(),
            'pending_adjustments' => DB::table('financial_adjustments')->where('status', 'pending')->count(),
            'month' => $this->summary(null, $month, now(), true),
        ];
    }

    /** @return Builder<Booking> */
    private function bookings(?int $tenantId): Builder
    {
        return Booking::query()->withoutGlobalScope(TenantScope::class)
            ->when($tenantId, fn (Builder $q) => $q->where('bookings.tenant_id', $tenantId));
    }
}
