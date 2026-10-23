<?php

namespace App\Domain\Providers\Exceptions;

use Throwable;

/** Request sent but no definitive answer received: the provider MAY have performed it. */
class ProviderTimeoutException extends ProviderException
{
    public static function for(string $provider, string $operation, ?Throwable $previous = null): self
    {
        return new self($provider, "Provider did not answer {$operation} in time; outcome unknown", 'PROVIDER_TIMEOUT', true, null, $previous);
    }
}
