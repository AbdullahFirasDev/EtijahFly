<?php

namespace App\Domain\FlightSearch;

use App\Domain\Commissions\PricingContext;
use App\Domain\Commissions\PricingEngine;
use App\Domain\FlightSearch\Models\FlightOffer;
use App\Domain\Providers\ProviderManager;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Shared\Money\Money;
use Illuminate\Support\Carbon;

/**
 * Re-checks price and availability with the provider immediately before booking and
 * re-applies the pricing rules to the confirmed supplier fare.
 */
class OfferPricingService
{
    public function __construct(
        private readonly ProviderManager $providers,
        private readonly PricingEngine $pricing,
    ) {}

    /** @return array{offer: FlightOffer, price_changed: bool, previous_total: Money, current_total: Money} */
    public function recheck(FlightOffer $offer): array
    {
        if ($offer->isExpired()) {
            throw BusinessRuleException::make('OFFER_EXPIRED', __('errors.offer_expired'), 410);
        }

        $provider = $this->providers->get($offer->provider_code);
        $result = $provider->priceCheck($offer->provider_offer_id);

        if (! $result->available || ! $result->fare) {
            throw BusinessRuleException::make('OFFER_UNAVAILABLE', __('errors.offer_unavailable'), 409, [
                'provider_message' => $result->message,
            ]);
        }

        $previous = Money::of((string) $offer->sale_total, $offer->currency);

        $breakdown = $this->pricing->price($result->fare, new PricingContext(
            tenantId: $offer->tenant_id,
            airlineCode: $offer->validating_airline,
            cabin: $offer->cabin,
            tripType: $offer->search->trip_type,
            origin: $offer->itinerary[0]['origin'],
            destination: $offer->itinerary[0]['destination'],
            providerCode: $offer->provider_code,
        ));

        $offer->forceFill([
            'provider_offer_id' => $result->providerOfferId ?? $offer->provider_offer_id,
            'currency' => $result->fare->currency(),
            'supplier_base' => $result->fare->base->toDecimal(),
            'supplier_taxes' => $result->fare->taxes->toDecimal(),
            'supplier_total' => $result->fare->total()->toDecimal(),
            'pricing' => $breakdown->toArray() + ['seats_remaining' => $offer->pricing['seats_remaining'] ?? null, 'rechecked_at' => now()->toIso8601String()],
            'sale_total' => $breakdown->saleTotal()->toDecimal(),
            'expires_at' => $result->expiresAt ? Carbon::parse($result->expiresAt)->min($offer->expires_at->copy()->addMinutes(20)) : $offer->expires_at,
        ])->save();

        $current = $breakdown->saleTotal();

        return [
            'offer' => $offer,
            'price_changed' => ! $current->equals($previous),
            'previous_total' => $previous,
            'current_total' => $current,
        ];
    }
}
