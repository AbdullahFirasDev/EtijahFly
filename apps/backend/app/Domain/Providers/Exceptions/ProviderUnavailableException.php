<?php

namespace App\Domain\Providers\Exceptions;

use Throwable;

class ProviderUnavailableException extends ProviderException
{
    public static function for(string $provider, string $message = 'Provider is unavailable', ?int $status = null, ?Throwable $previous = null): self
    {
        return new self($provider, $message, 'PROVIDER_UNAVAILABLE', true, $status, $previous);
    }
}
