<?php

use App\Domain\Bookings\HoldExpiryService;
use App\Domain\Providers\Exceptions\ProviderException;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureActiveSession;
use App\Http\Middleware\EnsurePortal;
use App\Http\Middleware\IdempotencyKey;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTenantContext;
use App\Http\Middleware\TrustPortalClientIp;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend([TrustPortalClientIp::class, AssignRequestId::class, SecurityHeaders::class]);
        $middleware->api(prepend: [SetLocale::class]);

        $middleware->alias([
            'portal' => EnsurePortal::class,
            'session.active' => EnsureActiveSession::class,
            'tenant' => SetTenantContext::class,
            'idempotent' => IdempotencyKey::class,
        ]);

        // Tenant context must exist before route-model binding resolves {booking} etc.
        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            AuthenticatesRequests::class,
            EnsureActiveSession::class,
            EnsurePortal::class,
            SetTenantContext::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            AuthenticatesSessions::class,
            SubstituteBindings::class,
            Authorize::class,
            IdempotencyKey::class,
        ]);

        // The API is token-based; there is no browser session to redirect to a login page.
        $middleware->redirectGuestsTo(fn () => null);
        // Only the portals' servers / load balancers may set X-Forwarded-For (client IP for
        // rate limits and audit logs). Override with a real environment variable in production.
        $middleware->trustProxies(at: ($proxies = getenv('TRUSTED_PROXIES'))
            ? array_map('trim', explode(',', $proxies))
            : ['127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16']);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->call(fn () => app(HoldExpiryService::class)->expireDue())->name('bookings:expire-holds')->everyMinute()->withoutOverlapping();
        $schedule->call(fn () => app(HoldExpiryService::class)->warnUpcoming())->name('bookings:warn-holds')->everyFiveMinutes()->withoutOverlapping();
        $schedule->call(fn () => Cache::forever('scheduler:heartbeat', now()->toIso8601String()))->name('scheduler:heartbeat')->everyMinute();
        $schedule->command('sanctum:prune-expired --hours=24')->daily();
        $schedule->command('queue:prune-failed --hours=720')->daily();
        $schedule->call(fn () => DB::table('idempotency_keys')->where('created_at', '<', now()->subDays(7))->delete())->name('idempotency:prune')->daily();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        $exceptions->dontReport([BusinessRuleException::class]);

        $exceptions->render(fn (BusinessRuleException $e) => response()->json(array_filter([
            'message' => $e->getMessage(),
            'code' => $e->errorCode,
            'details' => $e->details ?: null,
        ]), $e->status));

        $exceptions->render(fn (ProviderException $e) => response()->json([
            'message' => __('errors.provider_error'),
            'code' => $e->errorCode,
            'details' => ['provider' => $e->providerCode, 'retryable' => $e->retryable],
        ], $e->errorCode === 'PROVIDER_CAPABILITY_UNSUPPORTED' ? 422 : 502));

        $exceptions->render(fn (ValidationException $e) => response()->json([
            'message' => $e->getMessage(),
            'code' => 'VALIDATION_FAILED',
            'errors' => $e->errors(),
        ], $e->status));

        $exceptions->render(fn (AuthenticationException $e) => response()->json([
            'message' => __('errors.unauthenticated'), 'code' => 'UNAUTHENTICATED',
        ], 401));

        $exceptions->render(fn (AuthorizationException|AccessDeniedHttpException $e) => response()->json([
            'message' => __('errors.forbidden'), 'code' => 'FORBIDDEN',
        ], 403));

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => __('errors.not_found'), 'code' => 'NOT_FOUND'], 404);
            }
        });

        $exceptions->render(fn (ModelNotFoundException $e) => response()->json([
            'message' => __('errors.not_found'), 'code' => 'NOT_FOUND',
        ], 404));

        $exceptions->render(fn (ThrottleRequestsException $e) => response()->json([
            'message' => __('errors.too_many_requests'), 'code' => 'TOO_MANY_REQUESTS',
        ], 429, $e->getHeaders()));
    })->create();
