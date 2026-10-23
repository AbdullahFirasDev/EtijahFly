<?php

use App\Domain\Airlines\AirlineDirectory;
use App\Domain\Airlines\AirlinesInfoProvider;
use App\Domain\Airlines\Models\Airline;
use App\Domain\Providers\Exceptions\ProviderAuthenticationException;
use App\Domain\Providers\Exceptions\ProviderNotConfiguredException;
use App\Domain\Providers\Exceptions\ProviderRateLimitException;
use App\Domain\Providers\Exceptions\ProviderUnavailableException;
use App\Domain\Providers\Models\ProviderApiLog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** Shape captured from a real call to GET /airlines?iata=IA (2026-10-23). */
const IRAQI_AIRWAYS = [[
    'name' => 'Iraqi Airways', 'country' => 'Iraq', 'founded_year' => '1945',
    'home_airport' => 'Baghdad International Airport', 'iata_code' => 'IA', 'icao_code' => 'IAW',
    'fleet' => ['A320' => 3, 'A321' => 2, 'B738' => 15, 'total_count' => 32],
]];

beforeEach(function () {
    config(['providers.airlines_api.key' => 'test-key', 'providers.airlines_api.base_url' => 'https://airlines-api.test']);
});

it('sends the API key header and parses the array response', function () {
    Http::fake(['airlines-api.test/airlines*' => Http::response(IRAQI_AIRWAYS, 200, [
        'X-RateLimit-Requests-Limit' => '100', 'X-RateLimit-Requests-Remaining' => '97', 'X-RateLimit-Requests-Reset' => '1795307557',
    ])]);

    $result = app(AirlinesInfoProvider::class)->findByIata('ia');

    expect($result[0]->name)->toBe('Iraqi Airways')->and($result[0]->fleetTotal)->toBe(32);
    Http::assertSent(fn (Request $r) => $r->hasHeader('API-Key', 'test-key') && $r['iata'] === 'IA' && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/airlines'));
    expect(app(AirlinesInfoProvider::class)->quota())->toMatchArray(['limit' => 100, 'remaining' => 97]);
});

it('also accepts a single-object response (as shown in the documentation)', function () {
    Http::fake(['*' => Http::response(IRAQI_AIRWAYS[0])]);

    expect(app(AirlinesInfoProvider::class)->findByIcao('IAW'))->toHaveCount(1);
});

it('caches lookups so repeated reads do not spend quota', function () {
    Http::fake(['*' => Http::response(IRAQI_AIRWAYS)]);
    $provider = app(AirlinesInfoProvider::class);

    $provider->findByIata('IA');
    $provider->findByIata('IA');

    Http::assertSentCount(1);
});

it('maps 401 to an authentication error without retrying', function () {
    Http::fake(['*' => Http::response(['message' => 'Invalid API key'], 401)]);

    expect(fn () => app(AirlinesInfoProvider::class)->findByIata('IA'))->toThrow(ProviderAuthenticationException::class);
    Http::assertSentCount(1);
    expect(ProviderApiLog::query()->where('error_code', 'PROVIDER_AUTH_FAILED')->exists())->toBeTrue();
});

it('maps 429 to a rate-limit error without retrying', function () {
    Http::fake(['*' => Http::response(['message' => 'quota exceeded'], 429, ['Retry-After' => '3600'])]);

    try {
        app(AirlinesInfoProvider::class)->findByIata('IA');
        $this->fail('expected exception');
    } catch (ProviderRateLimitException $e) {
        expect($e->retryAfterSeconds)->toBe(3600)->and($e->retryable)->toBeTrue();
    }
    Http::assertSentCount(1);
});

it('retries server errors and then reports the provider as unavailable', function () {
    Http::fake(['*' => Http::response('oops', 503)]);

    expect(fn () => app(AirlinesInfoProvider::class)->findByIata('IA'))->toThrow(ProviderUnavailableException::class);
    Http::assertSentCount(2);
});

it('recovers when a retry succeeds', function () {
    Http::fake(['*' => Http::sequence()->push('down', 502)->push(IRAQI_AIRWAYS, 200)]);

    expect(app(AirlinesInfoProvider::class)->findByIata('IA'))->toHaveCount(1);
});

it('stops before exceeding the monthly budget', function () {
    config(['providers.airlines_api.monthly_budget' => 2]);
    Http::fake(['*' => Http::response(IRAQI_AIRWAYS)]);
    $provider = app(AirlinesInfoProvider::class);

    $provider->findByIata('IA');
    $provider->findByIata('FZ');

    expect(fn () => $provider->findByIata('EK'))->toThrow(ProviderRateLimitException::class);
    Http::assertSentCount(2);
});

it('fails clearly when no key is configured', function () {
    config(['providers.airlines_api.key' => null]);
    Http::fake();

    expect(fn () => app(AirlinesInfoProvider::class)->findByIata('IA'))->toThrow(ProviderNotConfiguredException::class);
    Http::assertNothingSent();
});

it('imports airlines into the directory through the admin API', function () {
    Http::fake(['*' => Http::response(IRAQI_AIRWAYS)]);
    actingAsAdmin(platformUser());

    $this->postJson('/api/v1/admin/airlines/import', ['by' => 'iata', 'value' => 'IA'])->assertOk()
        ->assertJsonPath('data.0.name', 'Iraqi Airways');

    $airline = Airline::query()->where('icao_code', 'IAW')->firstOrFail();
    expect($airline->source)->toBe('airlines_api')->and($airline->fleet['total_count'])->toBe(32)->and($airline->home_airport)->toBe('Baghdad International Airport');
    expect(app(AirlineDirectory::class)->namesFor(['IA'])['IA']->name)->toBe('Iraqi Airways');
});

it('never exposes the API key through the admin providers endpoint', function () {
    actingAsAdmin(platformUser());

    $body = $this->getJson('/api/v1/admin/providers')->assertOk()->getContent();
    expect($body)->not->toContain('test-key')->toContain('"credentials_configured":true');
});
