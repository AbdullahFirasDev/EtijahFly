<?php

namespace App\Domain\Providers\Booking\Data;

final readonly class SearchResult
{
    /** @param list<FlightOfferData> $offers */
    public function __construct(public array $offers) {}
}
