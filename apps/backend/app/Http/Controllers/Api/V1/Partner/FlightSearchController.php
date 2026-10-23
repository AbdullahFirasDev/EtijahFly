<?php

namespace App\Http\Controllers\Api\V1\Partner;

use App\Domain\Airlines\AirlineDirectory;
use App\Domain\FlightSearch\FlightSearchService;
use App\Domain\FlightSearch\Models\FlightOffer;
use App\Domain\FlightSearch\OfferPricingService;
use App\Http\Controllers\Controller;
use App\Http\Requests\SearchFlightsRequest;
use App\Http\Resources\FlightOfferResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class FlightSearchController extends Controller
{
    public function __construct(
        private readonly FlightSearchService $search,
        private readonly OfferPricingService $pricing,
        private readonly AirlineDirectory $airlines,
    ) {}

    /**
     * Search all enabled booking providers. Offers from sandbox providers carry is_test=true
     * and must be shown as simulated data.
     */
    public function search(SearchFlightsRequest $request): JsonResponse
    {
        $search = $this->search->search($request->user(), $request->criteria());
        $this->loadAirlineNames($search->offers);

        return response()->json([
            'search_id' => $search->id,
            'status' => $search->status,
            'provider_errors' => $search->provider_errors,
            'result_count' => $search->result_count,
            'has_test_offers' => $search->offers->contains('is_test', true),
            'offers' => FlightOfferResource::collection($search->offers),
        ]);
    }

    public function show(FlightOffer $offer): JsonResponse
    {
        $this->authorize('flights.search');
        $this->loadAirlineNames(collect([$offer]));

        return response()->json(['offer' => new FlightOfferResource($offer)]);
    }

    /** Re-check price and availability with the provider before booking. */
    public function priceCheck(FlightOffer $offer): JsonResponse
    {
        $this->authorize('bookings.create');
        $offer->load('search');
        $result = $this->pricing->recheck($offer);
        $this->loadAirlineNames(collect([$result['offer']]));

        return response()->json([
            'available' => true,
            'price_changed' => $result['price_changed'],
            'previous_total' => $result['previous_total'],
            'current_total' => $result['current_total'],
            'offer' => new FlightOfferResource($result['offer']),
        ]);
    }

    /** @param Collection<int, FlightOffer> $offers */
    private function loadAirlineNames(Collection $offers): void
    {
        $codes = $offers->flatMap(fn (FlightOffer $o) => collect($o->itinerary)->flatMap(fn ($s) => collect($s['segments'])->pluck('marketing_carrier')))
            ->push(...$offers->pluck('validating_airline'))
            ->filter()->unique()->values()->all();

        FlightOfferResource::$airlineNames = $this->airlines->namesFor($codes)->map(fn ($a) => $a->name)->all();
    }
}
