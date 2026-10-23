<?php

namespace App\Domain\Providers\Exceptions;

class ProviderNotConfiguredException extends ProviderException
{
    public static function for(string $provider, string $what = 'credentials'): self
    {
        return new self($provider, "Provider [{$provider}] is not configured ({$what} missing)", 'PROVIDER_NOT_CONFIGURED', false);
    }
}
