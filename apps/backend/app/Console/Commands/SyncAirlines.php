<?php

namespace App\Console\Commands;

use App\Domain\Airlines\AirlineDirectory;
use App\Domain\Airlines\AirlinesInfoProvider;
use App\Domain\Providers\Exceptions\ProviderException;
use Illuminate\Console\Command;

class SyncAirlines extends Command
{
    protected $signature = 'airlines:sync {--iata=* : IATA codes to import} {--icao=* : ICAO codes} {--name= : Name search}';

    protected $description = 'Import airline details from the Airlines API (each code = 1 request unless cached)';

    public function handle(AirlineDirectory $directory, AirlinesInfoProvider $api): int
    {
        $jobs = array_merge(
            array_map(fn ($c) => ['iata', $c], (array) $this->option('iata')),
            array_map(fn ($c) => ['icao', $c], (array) $this->option('icao')),
            $this->option('name') ? [['name', (string) $this->option('name')]] : [],
        );

        if ($jobs === []) {
            $this->warn('Nothing to sync. Example: php artisan airlines:sync --iata=IA --iata=FZ');

            return self::INVALID;
        }

        foreach ($jobs as [$by, $value]) {
            try {
                $airlines = $directory->import($by, $value);
                $this->info(sprintf('%s=%s: %d airline(s) %s', $by, $value, count($airlines), implode(', ', array_map(fn ($a) => $a->name, $airlines))));
            } catch (ProviderException $e) {
                $this->error("{$by}={$value}: {$e->errorCode} {$e->getMessage()}");
            }
        }

        $quota = $api->quota();
        $this->line(sprintf('Quota: used this month %d / budget %d (provider remaining: %s)', $quota['used_this_month'], $quota['budget'], $quota['remaining'] ?? 'unknown'));

        return self::SUCCESS;
    }
}
