<?php

use App\Domain\Providers\Booking\MockFlightProvider;

return [
    /*
     * Booking providers (implement FlightProviderInterface), keyed by provider code.
     * Enable/disable and non-secret settings are managed in the admin portal (flight_providers
     * table); credentials only ever come from the environment.
     *
     * To add a real GDS/NDC/consolidator integration: implement FlightProviderInterface,
     * register it here with its env-based credentials, and enable it in the admin portal.
     */
    'booking' => [
        'mock' => [
            'class' => MockFlightProvider::class,
            'name' => 'Mock Flight Provider (simulation)',
            // Never available in production unless explicitly forced on.
            'enabled' => (bool) env('MOCK_FLIGHT_PROVIDER_ENABLED', env('APP_ENV') !== 'production'),
            'sandbox' => true,
            'credentials' => [],
        ],
    ],

    'airlines_api' => [
        'code' => 'airlines_api',
        'name' => 'Airlines API (omkar.cloud)',
        'base_url' => env('AIRLINES_API_BASE_URL', 'https://airlines-api.omkar.cloud'),
        'key' => env('AIRLINES_API_KEY'),
        'timeout' => (int) env('AIRLINES_API_TIMEOUT', 10),
        // Hard stop below the plan's monthly quota so the platform never triggers overage.
        'monthly_budget' => (int) env('AIRLINES_API_MONTHLY_BUDGET', 90),
        'cache_days' => 30,
    ],

    'run_external_tests' => (bool) env('RUN_EXTERNAL_TESTS', false),
];
