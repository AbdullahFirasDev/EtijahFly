<?php

namespace App\Domain\Providers;

use App\Domain\Providers\Booking\FlightProviderInterface;
use App\Domain\Providers\Booking\InstrumentedFlightProvider;
use App\Domain\Providers\Exceptions\ProviderNotConfiguredException;
use App\Domain\Providers\Models\FlightProvider;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;

/**
 * Resolves booking providers from config (code -> class, env credentials) and the
 * flight_providers registry (enabled flag, non-secret settings, priority).
 */
class ProviderManager
{
    /** @var array<string, FlightProviderInterface> */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    /**
     * Providers that may be used for new searches and bookings.
     *
     * @return Collection<int, FlightProviderInterface>
     */
    public function searchable(): Collection
    {
        return FlightProvider::query()
            ->where('kind', FlightProvider::KIND_BOOKING)
            ->where('is_enabled', true)
            ->orderBy('priority')
            ->get()
            ->filter(fn (FlightProvider $p) => $this->isConfigured($p->code) && config("providers.booking.{$p->code}.enabled"))
            ->map(fn (FlightProvider $p) => $this->get($p->code))
            ->values();
    }

    /**
     * Any configured provider, enabled or not: existing bookings must stay manageable
     * (retrieve, cancel, refund) even after a provider is switched off for new sales.
     */
    public function get(string $code): FlightProviderInterface
    {
        if (isset($this->resolved[$code])) {
            return $this->resolved[$code];
        }

        $class = config("providers.booking.{$code}.class");
        if (! $class) {
            throw ProviderNotConfiguredException::for($code, 'driver');
        }

        $record = FlightProvider::query()->where('code', $code)->first();
        $inner = $this->container->make($class, ['settings' => $record ? ($record->settings ?? []) : []]);

        return $this->resolved[$code] = new InstrumentedFlightProvider($inner, $this->container->make(ProviderCallLogger::class));
    }

    public function isConfigured(string $code): bool
    {
        return (bool) config("providers.booking.{$code}.class");
    }

    /** True if every credential key declared for the provider has a value in the environment. */
    public function hasCredentials(string $code): bool
    {
        $credentials = config("providers.booking.{$code}.credentials", []);

        return collect($credentials)->every(fn ($value) => filled($value));
    }

    /** Forget resolved instances (after settings change). */
    public function flush(): void
    {
        $this->resolved = [];
    }
}
