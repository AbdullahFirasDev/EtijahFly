<?php

namespace Database\Seeders;

use App\Domain\Airlines\Models\Airline;
use Illuminate\Database\Seeder;

/**
 * Minimal airline names/codes so the UI can label carriers without spending Airlines API
 * quota. Full details (fleet, hub, founding year) are imported on demand from the API
 * (admin portal or `php artisan airlines:sync`), which overwrites these rows.
 */
class AirlineSeeder extends Seeder
{
    private const AIRLINES = [
        ['IA', 'IAW', 'Iraqi Airways', 'Iraq'],
        ['FZ', 'FDB', 'flydubai', 'United Arab Emirates'],
        ['EK', 'UAE', 'Emirates', 'United Arab Emirates'],
        ['EY', 'ETD', 'Etihad Airways', 'United Arab Emirates'],
        ['G9', 'ABY', 'Air Arabia', 'United Arab Emirates'],
        ['QR', 'QTR', 'Qatar Airways', 'Qatar'],
        ['TK', 'THY', 'Turkish Airlines', 'Turkey'],
        ['PC', 'PGT', 'Pegasus Airlines', 'Turkey'],
        ['RJ', 'RJA', 'Royal Jordanian', 'Jordan'],
        ['GF', 'GFA', 'Gulf Air', 'Bahrain'],
        ['SV', 'SVA', 'Saudia', 'Saudi Arabia'],
        ['XY', 'KNE', 'flynas', 'Saudi Arabia'],
        ['KU', 'KAC', 'Kuwait Airways', 'Kuwait'],
        ['WY', 'OMA', 'Oman Air', 'Oman'],
        ['MS', 'MSR', 'EgyptAir', 'Egypt'],
        ['ME', 'MEA', 'Middle East Airlines', 'Lebanon'],
        ['LH', 'DLH', 'Lufthansa', 'Germany'],
    ];

    public function run(): void
    {
        foreach (self::AIRLINES as [$iata, $icao, $name, $country]) {
            $airline = Airline::query()->firstOrNew(['icao_code' => $icao]);
            if ($airline->exists && $airline->source !== 'manual') {
                continue; // keep richer data imported from the Airlines API
            }
            $airline->fill(['iata_code' => $iata, 'name' => $name, 'country' => $country, 'source' => 'manual'])->save();
        }
    }
}
