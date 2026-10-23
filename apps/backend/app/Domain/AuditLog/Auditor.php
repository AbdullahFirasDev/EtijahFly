<?php

namespace App\Domain\AuditLog;

use App\Domain\AuditLog\Models\AuditLog;
use App\Domain\Identity\Models\User;
use BackedEnum;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Records sensitive operations. Values whose key looks like a secret (passwords, tokens,
 * API keys) or a travel document number are replaced before anything is written.
 */
class Auditor
{
    private const REDACTED = '[redacted]';

    private const SENSITIVE_KEY_PATTERN = '/(password|secret|token|api[_-]?key|authorization|cookie|document_number|passport|card)/i';

    public function __construct(
        private readonly Request $request,
        private readonly AuthFactory $auth,
    ) {}

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $action,
        ?Model $subject = null,
        array $old = [],
        array $new = [],
        array $metadata = [],
        ?int $tenantId = null,
        ?User $actor = null,
        string $actorType = 'user',
    ): AuditLog {
        $actor ??= $this->currentUser();

        $tenantId ??= $subject?->getAttribute('tenant_id') ?? $actor?->tenant_id;

        return AuditLog::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'actor_id' => $actor?->id,
            'actor_type' => $actor ? $actorType : ($actorType === 'user' ? 'system' : $actorType),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'old_values' => $old ? self::sanitize($old) : null,
            'new_values' => $new ? self::sanitize($new) : null,
            'metadata' => $metadata ? self::sanitize($metadata) : null,
            'ip_address' => $this->request->ip(),
            'user_agent' => substr((string) $this->request->userAgent(), 0, 512) ?: null,
            'request_id' => $this->request->attributes->get('request_id'),
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public static function sanitize(array $values): array
    {
        $clean = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY_PATTERN, $key)) {
                $clean[$key] = self::REDACTED;

                continue;
            }

            $clean[$key] = match (true) {
                is_array($value) => self::sanitize($value),
                $value instanceof BackedEnum => $value->value,
                $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
                is_object($value) && method_exists($value, '__toString') => (string) $value,
                is_object($value) => get_class($value),
                default => $value,
            };
        }

        return $clean;
    }

    /**
     * Attributes changed by the model's last save, before/after, for audit trails.
     *
     * @param  list<string>  $only
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function diff(Model $model, array $only = []): array
    {
        $changes = $model->getChanges();
        unset($changes['updated_at']);
        if ($only) {
            $changes = array_intersect_key($changes, array_flip($only));
        }

        $previous = $model->getPrevious();
        $old = [];
        foreach (array_keys($changes) as $key) {
            $old[$key] = $previous[$key] ?? null;
        }

        return [$old, $changes];
    }

    private function currentUser(): ?User
    {
        $user = $this->auth->guard('sanctum')->user();

        return $user instanceof User ? $user : null;
    }
}
