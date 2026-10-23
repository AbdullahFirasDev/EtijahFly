<?php

use App\Domain\Airlines\Data\AirlineData;

it('normalises an Airlines API record (shape observed from the live API)', function () {
    $data = AirlineData::fromApi([
        'name' => 'Iraqi Airways', 'country' => 'Iraq', 'founded_year' => '1945',
        'home_airport' => 'Baghdad International Airport', 'iata_code' => 'ia', 'icao_code' => 'IAW',
        'fleet' => ['A320' => 3, 'B738' => 15, 'total_count' => 32],
    ]);

    expect($data->name)->toBe('Iraqi Airways')
        ->and($data->iataCode)->toBe('IA')
        ->and($data->icaoCode)->toBe('IAW')
        ->and($data->fleet)->toBe(['A320' => 3, 'B738' => 15])
        ->and($data->fleetTotal)->toBe(32);
});

it('drops malformed codes instead of storing them', function () {
    $data = AirlineData::fromApi(['name' => 'X', 'iata_code' => 'ABC', 'icao_code' => '', 'fleet' => null]);

    expect($data->iataCode)->toBeNull()->and($data->icaoCode)->toBeNull()->and($data->fleet)->toBe([]);
});
