<?php

namespace App\Domain\Shared\Exceptions;

use RuntimeException;

/**
 * An expected business failure (insufficient credit, price changed, invalid transition...).
 * Rendered as JSON: { "message": "...", "code": "PRICE_CHANGED", "details": {...} }.
 */
class BusinessRuleException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string, mixed> $details */
    public static function make(string $code, string $message, int $status = 422, array $details = []): self
    {
        return new self($code, $message, $status, $details);
    }
}
