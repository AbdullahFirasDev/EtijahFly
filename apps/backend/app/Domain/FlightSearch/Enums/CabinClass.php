<?php

namespace App\Domain\FlightSearch\Enums;

enum CabinClass: string
{
    case Economy = 'economy';
    case PremiumEconomy = 'premium_economy';
    case Business = 'business';
    case First = 'first';
}
