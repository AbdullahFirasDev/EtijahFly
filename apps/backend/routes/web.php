<?php

use Illuminate\Support\Facades\Route;

// API-only application. Interactive API docs (Scramble) are served at /docs/api outside production.
Route::get('/', fn () => response()->json([
    'name' => config('platform.name'),
    'api' => url('/api/v1'),
    'docs' => app()->isProduction() ? null : url('/docs/api'),
]));
