<?php

namespace App\Http\Controllers\Api\V1\Partner;

use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Models\Booking;
use App\Domain\Finance\AccountService;
use App\Domain\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Http\Resources\LedgerAccountResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly AccountService $accounts) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenant = Tenant::query()->findOrFail($user->tenant_id);
        $visible = fn () => Booking::query()->when(! $user->can('bookings.view_all'), fn (Builder $q) => $q->where('created_by', $user->id));

        return response()->json([
            'account' => $user->can('finance.view') ? new LedgerAccountResource($this->accounts->walletFor($tenant)) : null,
            'counts' => $visible()->where('created_at', '>=', now()->subDays(30))
                ->selectRaw('status, count(*) as count')->groupBy('status')->pluck('count', 'status'),
            'expiring_holds' => BookingResource::collection($visible()
                ->where('status', BookingStatus::OnHold)
                ->where('hold_expires_at', '<=', now()->addHours(6))
                ->orderBy('hold_expires_at')->limit(5)->get()),
            'recent' => BookingResource::collection($visible()->latest('id')->limit(8)->get()),
            'unread_notifications' => $user->unreadNotifications()->count(),
        ]);
    }
}
