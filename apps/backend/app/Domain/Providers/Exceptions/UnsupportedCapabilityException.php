<?php

namespace App\Domain\Providers\Exceptions;

class UnsupportedCapabilityException extends ProviderException
{
    public static function for(string $provider, string $capability): self
    {
        return new self($provider, "Provider [{$provider}] does not support [{$capability}]", 'PROVIDER_CAPABILITY_UNSUPPORTED', false);
    }
}
