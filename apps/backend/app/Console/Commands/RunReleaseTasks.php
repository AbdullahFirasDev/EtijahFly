<?php

namespace App\Console\Commands;

use Database\Seeders\PermissionSeeder;
use Database\Seeders\ProviderSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** The deploy steps after `migrate` (render.yaml's pre-deploy command), for hosts without a pre-deploy step. */
class RunReleaseTasks extends Command
{
    protected $signature = 'platform:release-tasks {--once : Skip when the current release (platform.release) already ran them}';

    protected $description = 'Sync permissions and providers, re-apply database hardening and ensure storage buckets (idempotent)';

    private const MARKER = 'platform:release-tasks';

    public function handle(): int
    {
        $release = (string) config('platform.release');
        if ($this->option('once') && $release !== '' && Cache::get(self::MARKER) === $release) {
            $this->info("Release tasks already applied for {$release}.");

            return self::SUCCESS;
        }

        foreach ([
            ['db:seed', ['--class' => PermissionSeeder::class, '--force' => true]],
            ['db:seed', ['--class' => ProviderSeeder::class, '--force' => true]],
            ['platform:harden-db', []],
            ['platform:storage-setup', []],
        ] as [$command, $arguments]) {
            if ($this->call($command, $arguments) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        if ($release !== '') {
            Cache::forever(self::MARKER, $release);
        }

        return self::SUCCESS;
    }
}
