<?php

return [
    // local | supabase
    'driver' => env('FILE_STORAGE_DRIVER', 'local'),

    'signed_url_ttl' => (int) env('FILE_SIGNED_URL_TTL_SECONDS', 120),

    // Logical buckets used by the application.
    'buckets' => [
        'documents' => env('SUPABASE_BUCKET_DOCUMENTS', 'documents'),
        'branding' => env('SUPABASE_BUCKET_BRANDING', 'branding'),
        'payment_proofs' => env('SUPABASE_BUCKET_PAYMENT_PROOFS', 'payment-proofs'),
    ],

    'local' => [
        'disk' => 'local', // storage/app/private, never publicly served
    ],

    'supabase' => [
        'url' => rtrim((string) env('SUPABASE_URL', ''), '/'),
        // Service-role key: server only. All buckets are private.
        'service_key' => env('SUPABASE_SERVICE_ROLE_KEY'),
        'timeout' => 20,
    ],
];
