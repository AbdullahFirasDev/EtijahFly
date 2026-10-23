<?php

namespace App\Domain\Providers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $kind booking | airline_data
 * @property bool $is_enabled
 * @property bool $is_sandbox
 * @property int $priority
 * @property array<string, mixed>|null $settings
 * @property string $health_status
 * @property string|null $health_message
 * @property Carbon|null $health_checked_at
 */
class FlightProvider extends Model
{
    public const KIND_BOOKING = 'booking';

    public const KIND_AIRLINE_DATA = 'airline_data';

    protected $fillable = ['code', 'name', 'kind', 'is_enabled', 'is_sandbox', 'priority', 'settings'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_sandbox' => 'boolean',
            'settings' => 'array',
            'health_checked_at' => 'datetime',
        ];
    }
}
