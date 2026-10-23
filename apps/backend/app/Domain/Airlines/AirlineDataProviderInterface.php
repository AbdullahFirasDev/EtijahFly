<?php

namespace App\Domain\Airlines;

use App\Domain\Airlines\Data\AirlineData;

/**
 * Airline reference data (names, codes, hubs, fleet). Not a booking source: it cannot
 * search flights, price, hold or ticket.
 */
interface AirlineDataProviderInterface
{
    /** @return list<AirlineData> */
    public function findByIata(string $iata): array;

    /** @return list<AirlineData> */
    public function findByIcao(string $icao): array;

    /** @return list<AirlineData> */
    public function searchByName(string $name): array;

    public function isConfigured(): bool;
}
