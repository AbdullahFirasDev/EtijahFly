<?php

namespace App\Domain\FlightSearch\Enums;

enum TripType: string
{
    case OneWay = 'one_way';
    case RoundTrip = 'round_trip';
    case MultiCity = 'multi_city';
}
