<?php

namespace App\Domain\Airlines\Data;

final readonly class AirlineData
{
    /** @param array<string, int> $fleet aircraft type => count (without total_count) */
    public function __construct(
        public string $name,
        public ?string $iataCode,
        public ?string $icaoCode,
        public ?string $country,
        public ?string $foundedYear,
        public ?string $homeAirport,
        public array $fleet,
        public ?int $fleetTotal,
    ) {}

    /** @param array<string, mixed> $row raw item from the Airlines API */
    public static function fromApi(array $row): self
    {
        $fleet = is_array($row['fleet'] ?? null) ? $row['fleet'] : [];
        $total = isset($fleet['total_count']) ? (int) $fleet['total_count'] : null;
        unset($fleet['total_count']);

        return new self(
            name: trim((string) ($row['name'] ?? '')),
            iataCode: self::code($row['iata_code'] ?? null, 2),
            icaoCode: self::code($row['icao_code'] ?? null, 3),
            country: isset($row['country']) ? (string) $row['country'] : null,
            foundedYear: isset($row['founded_year']) && $row['founded_year'] !== '' ? substr((string) $row['founded_year'], 0, 4) : null,
            homeAirport: isset($row['home_airport']) ? (string) $row['home_airport'] : null,
            fleet: array_map('intval', array_filter($fleet, 'is_numeric')),
            fleetTotal: $total,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'iata_code' => $this->iataCode,
            'icao_code' => $this->icaoCode,
            'country' => $this->country,
            'founded_year' => $this->foundedYear,
            'home_airport' => $this->homeAirport,
            'fleet' => $this->fleet,
            'fleet_total' => $this->fleetTotal,
        ];
    }

    private static function code(mixed $value, int $length): ?string
    {
        $value = strtoupper(trim((string) $value));

        return strlen($value) === $length ? $value : null;
    }
}
