<?php

namespace App\Domain\Airlines\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $iata_code
 * @property string|null $icao_code
 * @property string $name
 * @property string|null $country
 * @property string|null $founded_year
 * @property string|null $home_airport
 * @property array<string, int>|null $fleet
 * @property string $source
 * @property Carbon|null $synced_at
 */
class Airline extends Model
{
    protected $fillable = ['iata_code', 'icao_code', 'name', 'country', 'founded_year', 'home_airport', 'fleet', 'source', 'synced_at'];

    protected function casts(): array
    {
        return ['fleet' => 'array', 'synced_at' => 'datetime'];
    }
}
