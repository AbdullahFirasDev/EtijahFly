<?php

use App\Domain\Shared\Database\PostgresHardening;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Keep this as the last migration of a release. Later migrations that add tables must
 * call PostgresHardening again (or run `php artisan platform:harden-db`); the test suite
 * fails if any table is left without RLS.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new PostgresHardening(DB::connection()))->apply();
    }

    public function down(): void
    {
        // Intentionally irreversible: tables are dropped by their own migrations.
    }
};
