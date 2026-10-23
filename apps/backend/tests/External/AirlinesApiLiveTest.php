<?php

use App\Domain\Airlines\AirlinesInfoProvider;
use App\Domain\Providers\Exceptions\ProviderAuthenticationException;
use App\Domain\Providers\ProviderCallLogger;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory;

/*
 * Live calls to https://airlines-api.omkar.cloud using AIRLINES_API_KEY from the environment.
 * Opt-in (RUN_EXTERNAL_TESTS=true) because each run spends real quota (2 requests, free plan
 * = 100/month). Uses an isolated in-memory cache and no database so nothing is persisted.
 *
 *   RUN_EXTERNAL_TESTS=true php vendor/bin/pest --testsuite=External
 */

beforeEach(function () {
    if (! filter_var(getenv('RUN_EXTERNAL_TESTS') ?: ($_ENV['RUN_EXTERNAL_TESTS'] ?? false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_EXTERNAL_TESTS=true to call the real Airlines API.');
    }
    if (! config('providers.airlines_api.key')) {
        $this->markTestSkipped('AIRLINES_API_KEY is not set.');
    }
});

function liveClient(?string $key = null): AirlinesInfoProvider
{
    $logger = Mockery::mock(ProviderCallLogger::class);
    $logger->shouldReceive('call')->andReturnUsing(fn ($provider, $operation, callable $call) => $call());

    return new class(new Factory, new Repository(new ArrayStore), $logger, (string) config('providers.airlines_api.base_url'), $key ?? config('providers.airlines_api.key'), 15) extends AirlinesInfoProvider
    {
        public function __construct($http, $cache, $logger, $baseUrl, $key, $timeout)
        {
            parent::__construct($http, $cache, $logger, $baseUrl, $key, $timeout, monthlyBudget: 1000, cacheDays: 1);
        }

        public function requestsThisMonth(): int
        {
            return 0; // no database in this suite
        }
    };
}

it('fetches a real airline by IATA code with the configured key', function () {
    $client = liveClient();
    $results = $client->findByIata('IA');

    expect($results)->not->toBeEmpty();
    $airline = $results[0];
    expect($airline->iataCode)->toBe('IA')
        ->and($airline->icaoCode)->toBe('IAW')
        ->and($airline->name)->toContain('Iraqi')
        ->and($airline->fleet)->toBeArray();

    $quota = $client->quota();
    expect($quota['remaining'])->toBeInt()->and($quota['limit'])->toBeInt();
    fwrite(STDERR, sprintf("\n  Airlines API quota: %d of %d remaining\n", $quota['remaining'], $quota['limit']));
});

it('maps an invalid key to an authentication error', function () {
    liveClient('invalid-key-for-testing')->findByIata('FZ');
})->throws(ProviderAuthenticationException::class);
