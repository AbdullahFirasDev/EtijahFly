<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-validates the session on every request: disabled users and suspended tenants lose
 * access immediately, not when their token expires. (Absolute/idle expiry is enforced in
 * the Sanctum token callback registered in AppServiceProvider.)
 */
class EnsureActiveSession
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => __('errors.unauthenticated'), 'code' => 'UNAUTHENTICATED'], 401);
        }

        if (! $user->isActive()) {
            $user->currentAccessToken()?->delete();

            return response()->json(['message' => __('errors.account_disabled'), 'code' => 'ACCOUNT_DISABLED'], 401);
        }

        if ($user->tenant_id) {
            $tenant = Tenant::query()->find($user->tenant_id);
            if (! $tenant?->isActive()) {
                $user->currentAccessToken()?->delete();

                return response()->json(['message' => __('errors.tenant_suspended'), 'code' => 'TENANT_SUSPENDED'], 401);
            }
        }

        return $next($request);
    }
}
