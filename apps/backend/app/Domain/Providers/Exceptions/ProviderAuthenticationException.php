<?php

namespace App\Domain\Providers\Exceptions;

class ProviderAuthenticationException extends ProviderException
{
    public static function for(string $provider, ?int $status = null): self
    {
        return new self($provider, 'Provider rejected the credentials', 'PROVIDER_AUTH_FAILED', false, $status);
    }
}
