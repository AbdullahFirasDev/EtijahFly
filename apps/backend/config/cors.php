<?php

/*
| The portals call the API from their own servers (BFF), so browsers normally never make
| cross-origin requests to it. CORS is still restricted to the known portal origins for any
| direct browser access; tokens are bearer tokens, so credentials (cookies) are not allowed.
*/

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Accept-Language', 'Authorization', 'Content-Type', 'Idempotency-Key', 'X-Request-Id', 'X-Locale'],

    'exposed_headers' => ['X-Request-Id', 'Idempotent-Replayed'],

    'max_age' => 3600,

    'supports_credentials' => false,
];
