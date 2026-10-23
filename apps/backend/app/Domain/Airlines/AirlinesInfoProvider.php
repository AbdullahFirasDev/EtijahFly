<?php

namespace App\Domain\Airlines;

use App\Domain\Airlines\Data\AirlineData;
use App\Domain\Providers\Exceptions\ProviderAuthenticationException;
use App\Domain\Providers\Exceptions\ProviderException;
use App\Domain\Providers\Exceptions\ProviderNotConfiguredException;
use App\Domain\Providers\Exceptions\ProviderRateLimitException;
use App\Domain\Providers\Exceptions\ProviderUnavailableException;
use App\Domain\Providers\Models\ProviderApiLog;
use App\Domain\Providers\ProviderCallLogger;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

/**
 * Client for https://airlines-api.omkar.cloud (GET /airlines?name|iata|icao, header API-Key).
 *
 * The free plan allows 100 requests/month, so:
 *  - successful lookups are cached (config providers.airlines_api.cache_days);
 *  - a local monthly budget (below the plan quota) blocks calls before the provider would;
 *  - the provider's X-RateLimit-Requests-Remaining header is remembered for the admin portal;
 *  - only connection errors and 5xx are retried; 401/429 are never retried.
 */
class AirlinesInfoProvider implements AirlineDataProviderInterface
{
    public const CODE = 'airlines_api';

    private const QUOTA_CACHE_KEY = 'airlines_api:quota';

    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
        private readonly ProviderCallLogger $logger,
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeout,
        private readonly int $monthlyBudget,
        private readonly int $cacheDays,
    ) {}

    public function isConfigured(): bool
    {
        return filled($this->apiKey);
    }

    public function findByIata(string $iata): array
    {
        return $this->lookup(['iata' => strtoupper($iata)]);
    }

    public function findByIcao(string $icao): array
    {
        return $this->lookup(['icao' => strtoupper($icao)]);
    }

    public function searchByName(string $name): array
    {
        return $this->lookup(['name' => trim($name)]);
    }

    /**
     * HTTP requests sent this calendar month (successful or not), from the call log.
     * Budget refusals happen before the call and are therefore not counted.
     */
    public function requestsThisMonth(): int
    {
        return ProviderApiLog::query()
            ->where('provider_code', self::CODE)
            ->where('operation', 'lookup')
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    /** @return array{limit: int|null, remaining: int|null, reset_at: string|null, budget: int, used_this_month: int} */
    public function quota(): array
    {
        $remote = $this->cache->get(self::QUOTA_CACHE_KEY, []);

        return [
            'limit' => $remote['limit'] ?? null,
            'remaining' => $remote['remaining'] ?? null,
            'reset_at' => $remote['reset_at'] ?? null,
            'budget' => $this->monthlyBudget,
            'used_this_month' => $this->requestsThisMonth(),
        ];
    }

    /**
     * @param  array<string, string>  $query
     * @return list<AirlineData>
     */
    private function lookup(array $query): array
    {
        if (! $this->isConfigured()) {
            throw ProviderNotConfiguredException::for(self::CODE, 'AIRLINES_API_KEY');
        }

        $cacheKey = 'airlines_api:lookup:'.sha1(json_encode($query));
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return array_map(fn (array $row) => AirlineData::fromApi($row), $cached);
        }

        if ($this->requestsThisMonth() >= $this->monthlyBudget) {
            throw ProviderRateLimitException::for(self::CODE, null, "Monthly request budget ({$this->monthlyBudget}) for the Airlines API is exhausted");
        }

        $rows = $this->logger->call(self::CODE, 'lookup', fn () => $this->request($query), $query);

        $this->cache->put($cacheKey, $rows, now()->addDays($this->cacheDays));

        return array_map(fn (array $row) => AirlineData::fromApi($row), $rows);
    }

    /**
     * @param  array<string, string>  $query
     * @return list<array<string, mixed>>
     */
    private function request(array $query): array
    {
        try {
            $response = $this->http
                ->baseUrl($this->baseUrl)
                ->withHeaders(['API-Key' => (string) $this->apiKey])
                ->acceptJson()
                ->timeout($this->timeout)
                ->retry(
                    2,
                    fn (int $attempt) => $attempt * 500,
                    fn (\Throwable $e) => $e instanceof ConnectionException
                        || ($e instanceof RequestException && $e->response->serverError()),
                    throw: false,
                )
                ->get('/airlines', $query);
        } catch (ConnectionException $e) {
            throw ProviderUnavailableException::for(self::CODE, 'Could not reach the Airlines API', null, $e);
        }

        $this->rememberQuota($response);

        return match (true) {
            $response->status() === 401, $response->status() === 403 => throw ProviderAuthenticationException::for(self::CODE, $response->status()),
            $response->status() === 429 => throw ProviderRateLimitException::for(self::CODE, $this->retryAfter($response)),
            $response->status() === 404 => [],
            $response->serverError() => throw ProviderUnavailableException::for(self::CODE, 'Airlines API server error', $response->status()),
            ! $response->successful() => throw new ProviderException(self::CODE, 'Unexpected Airlines API response', 'PROVIDER_BAD_RESPONSE', false, $response->status()),
            default => $this->normalise($response),
        };
    }

    /**
     * The API documents a single object but returns an array; accept both.
     *
     * @return list<array<string, mixed>>
     */
    private function normalise(Response $response): array
    {
        $json = $response->json();
        if (! is_array($json)) {
            throw new ProviderException(self::CODE, 'Airlines API returned a non-JSON body', 'PROVIDER_BAD_RESPONSE', false, $response->status());
        }

        $rows = array_is_list($json) ? $json : (isset($json['name']) ? [$json] : ($json['data'] ?? []));

        return array_values(array_filter($rows, fn ($row) => is_array($row) && filled($row['name'] ?? null)));
    }

    private function rememberQuota(Response $response): void
    {
        $remaining = $response->header('X-RateLimit-Requests-Remaining');
        if ($remaining === '') {
            return;
        }

        $reset = $response->header('X-RateLimit-Requests-Reset');
        $this->cache->forever(self::QUOTA_CACHE_KEY, [
            'limit' => (int) $response->header('X-RateLimit-Requests-Limit') ?: null,
            'remaining' => (int) $remaining,
            'reset_at' => is_numeric($reset) ? date(DATE_ATOM, (int) $reset) : null,
            'observed_at' => now()->toIso8601String(),
        ]);
    }

    private function retryAfter(Response $response): ?int
    {
        $value = $response->header('Retry-After');

        return is_numeric($value) ? (int) $value : null;
    }
}
