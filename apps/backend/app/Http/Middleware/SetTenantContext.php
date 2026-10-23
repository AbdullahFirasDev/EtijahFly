<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the request to the authenticated user's tenant. Must run before route-model
 * binding (see middleware priority in bootstrap/app.php) so that models of other tenants
 * resolve to 404 instead of being loaded.
 */
class SetTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        if (! $user || ! $user->tenant_id) {
            return response()->json(['message' => __('errors.forbidden'), 'code' => 'TENANT_REQUIRED'], 403);
        }

        $this->context->set($user->tenant_id);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
