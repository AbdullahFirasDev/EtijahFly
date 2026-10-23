<?php

return [
    'name' => env('APP_NAME', 'EtijahFly'),

    'portals' => [
        'partner' => env('PARTNER_PORTAL_URL', 'http://localhost:3000'),
        'admin' => env('ADMIN_PORTAL_URL', 'http://localhost:3001'),
    ],

    // Shared with the portal servers so they can pass the end user's IP when the API runs on a
    // separate host (see TrustPortalClientIp). Empty = disabled.
    'portal_proxy_secret' => env('PORTAL_PROXY_SECRET'),

    'auth' => [
        'token_ttl_minutes' => (int) env('AUTH_TOKEN_TTL_MINUTES', 480),
        'token_idle_minutes' => (int) env('AUTH_TOKEN_IDLE_MINUTES', 120),
        'invitation_ttl_hours' => 72,
        'max_sessions_per_user' => 10,
    ],

    'currencies' => ['IQD', 'USD'],

    'bookings' => [
        // How long a search result can be booked before a new search is required.
        'offer_ttl_minutes' => 30,
        // Warn agencies this long before a hold expires.
        'hold_expiry_warning_minutes' => 30,
        // Booking reference prefix (EF-XXXXXX).
        'reference_prefix' => 'EF',
        // Re-check ticketing outcome this many times when the provider response is unknown.
        'ticketing_verification_attempts' => 6,
    ],

    // Demo users' password for DevelopmentSeeder (local/testing only).
    'dev_seed_password' => env('DEV_SEED_PASSWORD'),

    'finance' => [
        // Platform fee retained on post-ticketing refunds, in the account currency.
        'refund_fee' => ['IQD' => 0, 'USD' => 0],
    ],
];
