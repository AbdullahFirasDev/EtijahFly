<?php

namespace App\Domain\Airlines\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Static reference data (seeded). Not provided by the Airlines API.
 *
 * @property int $id
 * @property string $iata_code
 * @property string|null $icao_code
 * @property string $name_en
 * @property string|null $name_ar
 * @property string $city_en
 * @property string|null $city_ar
 * @property string $country_code
 * @property string|null $timezone
 * @property bool $is_active
 */
class Airport extends Model
{
    protected $fillable = ['iata_code', 'icao_code', 'name_en', 'name_ar', 'city_en', 'city_ar', 'country_code', 'timezone', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
