<?php

namespace App\Http\Resources;

use App\Domain\FlightSearch\Models\FlightOffer;
use App\Http\Resources\Concerns\DetectsAudience;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FlightOffer */
class FlightOfferResource extends JsonResource
{
    use DetectsAudience;

    /** @var array<string, string> IATA => airline name */
    public static array $airlineNames = [];

    public function toArray(Request $request): array
    {
        $pricing = $this->pricing;

        return [
            'id' => $this->id,
            'provider' => $this->provider_code,
            'is_test' => $this->is_test,
            'validating_airline' => $this->validating_airline,
            'validating_airline_name' => self::$airlineNames[$this->validating_airline] ?? null,
            'itinerary' => $this->itinerary,
            'baggage' => $this->baggage,
            'fare_rules' => $this->fare_rules,
            'refundable' => $this->refundable,
            'cabin' => $this->cabin,
            'passengers' => $this->passengers,
            'seats_remaining' => isset($pricing['seats_remaining']) ? (int) $pricing['seats_remaining'] : null,
            'price' => [
                'currency' => $this->currency,
                'total' => (string) $this->sale_total,
                'commission' => (string) $pricing['commission'],
                'agency_net' => (string) $pricing['agency_net'],
            ],
            'internal_pricing' => $this->when($this->isInternal($request), fn () => $pricing),
            'expires_at' => $this->expires_at->toIso8601String(),
        ];
    }
}
