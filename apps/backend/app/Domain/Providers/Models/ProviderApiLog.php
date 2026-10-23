<?php

namespace App\Domain\Providers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $provider_code
 * @property string $operation
 * @property bool $success
 * @property int|null $http_status
 * @property int|null $duration_ms
 * @property string|null $error_code
 * @property string|null $error_message
 * @property array<string, mixed>|null $context
 * @property int|null $tenant_id
 * @property int|null $booking_id
 * @property string|null $request_id
 * @property Carbon $created_at
 */
class ProviderApiLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
