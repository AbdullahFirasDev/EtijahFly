<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route groups are bound to one portal: a partner token can never call admin routes and
 * vice versa, even if a role were mis-assigned.
 */
class EnsurePortal
{
    public function handle(Request $request, Closure $next, string $portal): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user || $user->portal() !== $portal || ! $user->tokenCan("portal:{$portal}")) {
            return response()->json(['message' => __('errors.forbidden'), 'code' => 'WRONG_PORTAL'], 403);
        }

        return $next($request);
    }
}
