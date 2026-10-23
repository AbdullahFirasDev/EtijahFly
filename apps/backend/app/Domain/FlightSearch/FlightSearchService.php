<?php

namespace App\Domain\FlightSearch;

use App\Domain\Commissions\PricingContext;
use App\Domain\Commissions\PricingEngine;
use App\Domain\FlightSearch\Enums\TripType;
use App\Domain\FlightSearch\Models\FlightOffer;
use App\Domain\FlightSearch\Models\FlightSearch;
use App\Domain\Identity\Models\User;
use App\Domain\Providers\Booking\Data\FlightOfferData;
use App\Domain\Providers\Booking\Data\SearchCriteria;
use App\Domain\Providers\Booking\FlightProviderInterface;
use App\Domain\Providers\Exceptions\ProviderException;
use App\Domain\Providers\ProviderManager;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Fans a search out to every enabled booking provider that supports the trip type,
 * prices each offer for the agency and stores the priced snapshot for booking.
 */
class FlightSearchService
{
    public function __construct(
        private readonly ProviderManager $providers,
        private readonly PricingEngine $pricing,
    ) {}

    public function search(User $user, SearchCriteria $criteria): FlightSearch
    {
        $started = hrtime(true);

        $providers = $this->providers->searchable()
            ->filter(fn (FlightProviderInterface $p) => $this->supports($p, $criteria->tripType));

        if ($providers->isEmpty()) {
            throw BusinessRuleException::make('NO_PROVIDER_AVAILABLE', __('errors.no_provider_available'), 503, [
                'trip_type' => $criteria->tripType->value,
            ]);
        }

        /** @var Collection<int, FlightOfferData> $offers */
        $offers = collect();
        $errors = [];
        foreach ($providers as $provider) {
            try {
                $offers = $offers->merge($provider->search($criteria)->offers);
            } catch (ProviderException $e) {
                $errors[$provider->code()] = ['code' => $e->errorCode, 'message' => $e->getMessage(), 'retryable' => $e->retryable];
            }
        }

        $search = FlightSearch::query()->create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'trip_type' => $criteria->tripType->value,
            'slices' => array_map(fn ($s) => $s->toArray(), $criteria->slices),
            'passengers' => $criteria->passengers->toArray(),
            'cabin' => $criteria->cabin->value,
            'status' => $errors === [] ? 'completed' : (count($errors) === $providers->count() ? 'failed' : 'partial'),
            'result_count' => $offers->count(),
            'provider_errors' => $errors ?: null,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ]);

        if ($search->status === 'failed') {
            throw BusinessRuleException::make('SEARCH_FAILED', __('errors.search_failed'), 502, ['providers' => $errors]);
        }

        foreach ($offers as $offer) {
            $this->storeOffer($search, $offer, $criteria);
        }

        return $search->load(['offers' => fn ($q) => $q->orderBy('sale_total')]);
    }

    private function storeOffer(FlightSearch $search, FlightOfferData $offer, SearchCriteria $criteria): FlightOffer
    {
        $breakdown = $this->pricing->price($offer->fare, new PricingContext(
            tenantId: $search->tenant_id,
            airlineCode: $offer->validatingAirline,
            cabin: $criteria->cabin->value,
            tripType: $criteria->tripType->value,
            origin: $criteria->slices[0]->origin,
            destination: $criteria->slices[0]->destination,
            providerCode: $offer->providerCode,
        ));

        $ttl = (int) config('platform.bookings.offer_ttl_minutes');
        $providerExpiry = Carbon::parse($offer->expiresAt);

        return FlightOffer::query()->create([
            'tenant_id' => $search->tenant_id,
            'flight_search_id' => $search->id,
            'provider_code' => $offer->providerCode,
            'provider_offer_id' => $offer->providerOfferId,
            'is_test' => $offer->isTest,
            'validating_airline' => $offer->validatingAirline,
            'itinerary' => $offer->itinerary(),
            'baggage' => $offer->baggage,
            'fare_rules' => $offer->fareRules,
            'refundable' => $offer->refundable,
            'cabin' => $offer->cabin->value,
            'passengers' => $criteria->passengers->toArray(),
            'currency' => $offer->fare->currency(),
            'supplier_base' => $offer->fare->base->toDecimal(),
            'supplier_taxes' => $offer->fare->taxes->toDecimal(),
            'supplier_total' => $offer->fare->total()->toDecimal(),
            'pricing' => $breakdown->toArray() + ['seats_remaining' => $offer->seatsRemaining],
            'sale_total' => $breakdown->saleTotal()->toDecimal(),
            'expires_at' => $providerExpiry->min(now()->addMinutes($ttl)),
        ]);
    }

    private function supports(FlightProviderInterface $provider, TripType $type): bool
    {
        $caps = $provider->capabilities();

        return $caps->search && match ($type) {
            TripType::OneWay => true,
            TripType::RoundTrip => $caps->roundTrip,
            TripType::MultiCity => $caps->multiCity,
        };
    }
}
