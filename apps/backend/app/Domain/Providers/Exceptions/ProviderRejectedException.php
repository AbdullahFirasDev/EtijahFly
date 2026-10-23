<?php

namespace App\Domain\Providers\Exceptions;

/** Business rejection by the provider (sold out, invalid passenger data, fare rules...). */
class ProviderRejectedException extends ProviderException
{
    public static function for(string $provider, string $message, string $code = 'PROVIDER_REJECTED'): self
    {
        return new self($provider, $message, $code, false);
    }
}
