<?php

namespace App\Domain\Providers\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The provider did not perform the requested operation (unless the subclass is
 * ProviderTimeoutException, where the outcome is unknown).
 */
class ProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $providerCode,
        string $message,
        public readonly string $errorCode = 'PROVIDER_ERROR',
        public readonly bool $retryable = false,
        public readonly ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
