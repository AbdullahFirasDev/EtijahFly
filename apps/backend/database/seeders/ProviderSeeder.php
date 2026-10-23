<?php

namespace Database\Seeders;

use App\Domain\Airlines\AirlinesInfoProvider;
use App\Domain\Providers\Booking\MockFlightProvider;
use App\Domain\Providers\Models\FlightProvider;
use Illuminate\Database\Seeder;

/** Registers known integrations. Never overwrites admin choices on re-run. */
class ProviderSeeder extends Seeder
{
    public function run(): void
    {
        FlightProvider::query()->firstOrCreate(['code' => MockFlightProvider::CODE], [
            'name' => config('providers.booking.mock.name'),
            'kind' => FlightProvider::KIND_BOOKING,
            'is_enabled' => (bool) config('providers.booking.mock.enabled'),
            'is_sandbox' => true,
            'priority' => 900,
            'settings' => ['scenario' => 'normal'],
        ]);

        FlightProvider::query()->firstOrCreate(['code' => AirlinesInfoProvider::CODE], [
            'name' => config('providers.airlines_api.name'),
            'kind' => FlightProvider::KIND_AIRLINE_DATA,
            'is_enabled' => filled(config('providers.airlines_api.key')),
            'is_sandbox' => false,
            'priority' => 100,
        ]);
    }
}
