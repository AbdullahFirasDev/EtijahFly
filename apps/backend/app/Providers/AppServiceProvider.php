<?php

namespace App\Providers;

use App\Domain\Airlines\AirlineDataProviderInterface;
use App\Domain\Airlines\AirlinesInfoProvider;
use App\Domain\Bookings\Models\Booking;
use App\Domain\Bookings\Models\Refund;
use App\Domain\Bookings\Models\Traveler;
use App\Domain\Commissions\Models\ExchangeRate;
use App\Domain\Commissions\Models\PricingRule;
use App\Domain\Finance\Models\FinancialAdjustment;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\LedgerAccount;
use App\Domain\Identity\Models\Invitation;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Payments\Models\Payment;
use App\Domain\Providers\Models\FlightProvider;
use App\Domain\Providers\ProviderCallLogger;
use App\Domain\Providers\ProviderManager;
use App\Domain\Shared\Storage\FileStorage;
use App\Domain\Shared\Storage\LocalFileStorage;
use App\Domain\Shared\Storage\SupabaseFileStorage;
use App\Domain\Shared\Tenancy\TenantContext;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantBranding;
use App\Policies\BookingPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(ProviderManager::class);

        $this->app->singleton(FileStorage::class, function ($app) {
            return config('filestorage.driver') === 'supabase'
                ? new SupabaseFileStorage(
                    $app->make(Http::class),
                    (string) config('filestorage.supabase.url'),
                    (string) config('filestorage.supabase.service_key'),
                    (int) config('filestorage.supabase.timeout'),
                )
                : new LocalFileStorage(Storage::disk(config('filestorage.local.disk')));
        });

        $this->app->bind(AirlineDataProviderInterface::class, AirlinesInfoProvider::class);
        $this->app->bind(AirlinesInfoProvider::class, fn ($app) => new AirlinesInfoProvider(
            http: $app->make(Http::class),
            cache: $app->make(Cache::class),
            logger: $app->make(ProviderCallLogger::class),
            baseUrl: (string) config('providers.airlines_api.base_url'),
            apiKey: config('providers.airlines_api.key'),
            timeout: (int) config('providers.airlines_api.timeout'),
            monthlyBudget: (int) config('providers.airlines_api.monthly_budget'),
            cacheDays: (int) config('providers.airlines_api.cache_days'),
        ));
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Relation::morphMap([
            'user' => User::class,
            'tenant' => Tenant::class,
            'tenant_branding' => TenantBranding::class,
            'invitation' => Invitation::class,
            'role' => Role::class,
            'booking' => Booking::class,
            'refund' => Refund::class,
            'traveler' => Traveler::class,
            'payment' => Payment::class,
            'invoice' => Invoice::class,
            'ledger_account' => LedgerAccount::class,
            'adjustment' => FinancialAdjustment::class,
            'pricing_rule' => PricingRule::class,
            'exchange_rate' => ExchangeRate::class,
            'flight_provider' => FlightProvider::class,
        ]);

        Gate::policy(Booking::class, BookingPolicy::class);

        // Idle timeout on top of the absolute expiry stored on each token.
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid): bool {
            $idle = (int) config('platform.auth.token_idle_minutes');

            return $isValid && ($token->last_used_at === null || $token->last_used_at->gt(now()->subMinutes($idle)));
        });

        Password::defaults(function () {
            $rule = Password::min(10)->letters()->mixedCase()->numbers()->symbols();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)->by('ip:'.$request->ip()),
            Limit::perMinute(5)->by('email:'.mb_strtolower((string) $request->input('email'))),
        ]);
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(3)->by('reset:'.$request->ip()));
        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(30)->by('search:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(60)->by('public:'.$request->ip()));
    }
}
