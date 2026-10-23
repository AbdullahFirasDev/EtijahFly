<?php

namespace App\Domain\Bookings\Enums;

use Carbon\CarbonInterface;

enum PassengerType: string
{
    case Adult = 'ADT';
    case Child = 'CHD';
    case Infant = 'INF';

    /** IATA age bands, evaluated on the date of the last flight. */
    public function acceptsAge(CarbonInterface $dateOfBirth, CarbonInterface $travelDate): bool
    {
        $age = (int) $dateOfBirth->diffInYears($travelDate);

        return match ($this) {
            self::Adult => $age >= 12,
            self::Child => $age >= 2 && $age < 12,
            self::Infant => $age < 2 && $dateOfBirth->lessThanOrEqualTo($travelDate),
        };
    }

    public function countKey(): string
    {
        return match ($this) {
            self::Adult => 'adults',
            self::Child => 'children',
            self::Infant => 'infants',
        };
    }
}
