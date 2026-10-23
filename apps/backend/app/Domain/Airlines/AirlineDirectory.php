<?php

namespace App\Domain\Airlines;

use App\Domain\Airlines\Data\AirlineData;
use App\Domain\Airlines\Models\Airline;
use Illuminate\Support\Collection;

/**
 * Local airline directory. Reads never call the external API (searches would burn the
 * monthly quota); imports from the Airlines API are explicit (admin action or
 * `php artisan airlines:sync`).
 */
class AirlineDirectory
{
    public function __construct(private readonly AirlineDataProviderInterface $provider) {}

    /**
     * @param  list<string>  $iataCodes
     * @return Collection<string, Airline> keyed by IATA code
     */
    public function namesFor(array $iataCodes): Collection
    {
        return Airline::query()
            ->whereIn('iata_code', array_unique($iataCodes))
            ->get()
            ->keyBy('iata_code');
    }

    /**
     * Fetch from the provider and upsert into the directory.
     *
     * @return list<Airline>
     */
    public function import(string $by, string $value): array
    {
        $results = match ($by) {
            'iata' => $this->provider->findByIata($value),
            'icao' => $this->provider->findByIcao($value),
            default => $this->provider->searchByName($value),
        };

        return array_map(fn (AirlineData $data) => $this->upsert($data), array_slice($results, 0, 25));
    }

    public function upsert(AirlineData $data): Airline
    {
        $airline = ($data->icaoCode ? Airline::query()->where('icao_code', $data->icaoCode)->first() : null)
            ?? ($data->iataCode ? Airline::query()->where('iata_code', $data->iataCode)->whereNull('icao_code')->first() : null)
            ?? new Airline;

        $airline->fill([
            'iata_code' => $data->iataCode,
            'icao_code' => $data->icaoCode,
            'name' => $data->name,
            'country' => $data->country,
            'founded_year' => $data->foundedYear,
            'home_airport' => $data->homeAirport,
            'fleet' => $data->fleet ? $data->fleet + ['total_count' => $data->fleetTotal ?? array_sum($data->fleet)] : null,
            'source' => AirlinesInfoProvider::CODE,
            'synced_at' => now(),
        ])->save();

        return $airline;
    }
}
