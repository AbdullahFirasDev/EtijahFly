<?php

use App\Domain\FlightSearch\Enums\CabinClass;
use App\Domain\FlightSearch\Enums\TripType;
use App\Domain\FlightSearch\Models\FlightSearch;
use App\Domain\Providers\Booking\Data\PassengerCounts;
use App\Domain\Providers\Booking\Data\SearchCriteria;
use App\Domain\Providers\Booking\Data\SearchSlice;
use App\Domain\Providers\Booking\MockFlightProvider;
use App\Domain\Providers\Models\FlightProvider;
use App\Domain\Providers\Models\ProviderApiLog;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->tenant = agency();
    actingAsPartner(agencyUser($this->tenant, 'booking_agent'));
});

it('returns priced, test-flagged offers from the mock provider', function () {
    $response = $this->postJson('/api/v1/partner/flights/search', searchPayload())->assertOk()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('has_test_offers', true);

    $offers = $response->json('offers');
    expect($offers)->not->toBeEmpty();

    $offer = $offers[0];
    expect($offer['is_test'])->toBeTrue()
        ->and($offer['provider'])->toBe('mock')
        ->and($offer['itinerary'][0]['origin'])->toBe('BGW')
        ->and($offer['itinerary'][0]['destination'])->toBe('DXB')
        ->and($offer['price'])->toHaveKeys(['currency', 'total', 'commission', 'agency_net'])
        ->and($offer)->not->toHaveKey('internal_pricing')
        ->and($offer['baggage'])->toHaveKeys(['checked', 'cabin']);

    // flight numbers in the reserved simulation range
    foreach ($offer['itinerary'][0]['segments'] as $segment) {
        expect((int) substr($segment['flight_number'], 2))->toBeGreaterThanOrEqual(9000);
    }

    // offers are sorted by price
    $totals = array_map(fn ($o) => (float) $o['price']['total'], $offers);
    $sorted = $totals;
    sort($sorted);
    expect($totals)->toBe($sorted);

    expect(ProviderApiLog::query()->where('provider_code', 'mock')->where('operation', 'search')->where('success', true)->exists())->toBeTrue();
});

it('supports round trips and multi-city when the provider does', function () {
    $round = $this->postJson('/api/v1/partner/flights/search', searchPayload([
        'trip_type' => 'round_trip',
        'slices' => [
            ['origin' => 'BGW', 'destination' => 'IST', 'date' => now()->addDays(10)->toDateString()],
            ['origin' => 'IST', 'destination' => 'BGW', 'date' => now()->addDays(17)->toDateString()],
        ],
    ]))->assertOk()->json('offers.0.itinerary');
    expect($round)->toHaveCount(2);

    $multi = $this->postJson('/api/v1/partner/flights/search', searchPayload([
        'trip_type' => 'multi_city',
        'slices' => [
            ['origin' => 'BGW', 'destination' => 'DXB', 'date' => now()->addDays(10)->toDateString()],
            ['origin' => 'DXB', 'destination' => 'CAI', 'date' => now()->addDays(14)->toDateString()],
            ['origin' => 'CAI', 'destination' => 'BGW', 'date' => now()->addDays(20)->toDateString()],
        ],
    ]))->assertOk()->json('offers.0.itinerary');
    expect($multi)->toHaveCount(3);
});

it('returns an empty result set when no flights are available', function () {
    mockScenario('no_results');

    $this->postJson('/api/v1/partner/flights/search', searchPayload())->assertOk()
        ->assertJsonPath('result_count', 0)
        ->assertJsonPath('offers', []);
});

it('reports provider outages as a failed search without inventing results', function () {
    mockScenario('search_error');

    $this->postJson('/api/v1/partner/flights/search', searchPayload())
        ->assertStatus(502)
        ->assertJsonPath('code', 'SEARCH_FAILED')
        ->assertJsonPath('details.providers.mock.code', 'PROVIDER_UNAVAILABLE');

    expect(FlightSearch::query()->first()->status)->toBe('failed')
        ->and(ProviderApiLog::query()->where('success', false)->count())->toBe(1);
});

it('refuses to search when no booking provider is enabled', function () {
    FlightProvider::query()->where('code', 'mock')->update(['is_enabled' => false]);

    $this->postJson('/api/v1/partner/flights/search', searchPayload())->assertStatus(503)->assertJsonPath('code', 'NO_PROVIDER_AVAILABLE');
});

it('validates search input', function (array $override, string $field) {
    $this->postJson('/api/v1/partner/flights/search', searchPayload($override))
        ->assertStatus(422)->assertJsonValidationErrors([$field]);
})->with([
    'unknown airport' => [['slices' => [['origin' => 'ZZZ']]], 'slices.0.origin'],
    'same origin/destination' => [['slices' => [['destination' => 'BGW']]], 'slices.0.destination'],
    'past date' => [['slices' => [['date' => '2020-01-01']]], 'slices.0.date'],
    'too many infants' => [['passengers' => ['adults' => 1, 'infants' => 2]], 'passengers.infants'],
    'round trip needs two slices' => [['trip_type' => 'round_trip'], 'slices'],
    'invalid cabin' => [['cabin' => 'luxury'], 'cabin'],
]);

it('generates deterministic schedules and only real carrier codes as labels', function () {
    $provider = new MockFlightProvider(Cache::store());
    $criteria = new SearchCriteria(TripType::OneWay, [new SearchSlice('BGW', 'DXB', now()->addDays(5)->toDateString())], new PassengerCounts(2, 1, 1), CabinClass::Business);

    $a = $provider->search($criteria)->offers;
    $b = $provider->search($criteria)->offers;

    expect(array_map(fn ($o) => $o->fare->total()->toDecimal(), $a))->toBe(array_map(fn ($o) => $o->fare->total()->toDecimal(), $b))
        ->and(collect($a)->every(fn ($o) => $o->isTest))->toBeTrue();
});
