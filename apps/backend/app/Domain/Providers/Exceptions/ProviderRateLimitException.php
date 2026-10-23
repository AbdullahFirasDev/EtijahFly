<?php

namespace App\Domain\Providers\Exceptions;

class ProviderRateLimitException extends ProviderException
{
    public ?int $retryAfterSeconds = null;

    public static function for(string $provider, ?int $retryAfterSeconds = null, string $message = 'Provider rate limit or quota exceeded'): self
    {
        $e = new self($provider, $message, 'PROVIDER_RATE_LIMITED', true, 429);
        $e->retryAfterSeconds = $retryAfterSeconds;

        return $e;
    }
}
