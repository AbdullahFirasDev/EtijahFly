<?php

namespace App\Console\Commands;

use App\Domain\Shared\Database\PostgresHardening;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class HardenDatabase extends Command
{
    protected $signature = 'platform:harden-db';

    protected $description = 'Re-apply append-only triggers and enable RLS on every public table (idempotent)';

    public function handle(): int
    {
        $hardening = new PostgresHardening(DB::connection());
        $hardening->apply();

        $missing = $hardening->tablesWithoutRls();
        if ($missing) {
            $this->error('Tables without RLS: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $this->info('Database hardening applied ('.count($hardening->publicTables()).' tables).');

        return self::SUCCESS;
    }
}
