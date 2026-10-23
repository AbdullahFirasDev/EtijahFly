<?php

namespace App\Domain\Providers;

use App\Domain\AuditLog\Auditor;
use App\Domain\Providers\Exceptions\ProviderException;
use App\Domain\Providers\Models\ProviderApiLog;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Times and records every outbound provider call (sanitised; never headers or keys). */
class ProviderCallLogger
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly Request $request,
    ) {}

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @param  array<string, mixed>  $context
     * @return T
     */
    public function call(string $provider, string $operation, callable $call, array $context = [], ?int $bookingId = null): mixed
    {
        $started = hrtime(true);

        try {
            $result = $call();
            $this->write($provider, $operation, true, $started, null, $context, $bookingId);

            return $result;
        } catch (Throwable $e) {
            $this->write($provider, $operation, false, $started, $e, $context, $bookingId);
            throw $e;
        }
    }

    /** @param array<string, mixed> $context */
    private function write(string $provider, string $operation, bool $success, int $started, ?Throwable $e, array $context, ?int $bookingId): void
    {
        try {
            ProviderApiLog::query()->create([
                'provider_code' => $provider,
                'operation' => $operation,
                'success' => $success,
                'http_status' => $e instanceof ProviderException ? $e->httpStatus : null,
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'error_code' => $e instanceof ProviderException ? $e->errorCode : ($e ? class_basename($e) : null),
                'error_message' => $e ? mb_substr($e->getMessage(), 0, 1000) : null,
                'context' => $context ? Auditor::sanitize($context) : null,
                'tenant_id' => $this->tenantContext->id(),
                'booking_id' => $bookingId,
                'request_id' => $this->request->attributes->get('request_id'),
            ]);
        } catch (Throwable $logFailure) {
            // Logging must never break a booking flow.
            Log::warning('Failed to record provider call', ['error' => $logFailure->getMessage()]);
        }
    }
}
