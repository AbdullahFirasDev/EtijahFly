<?php

namespace App\Domain\Bookings\Models;

use App\Domain\Bookings\Enums\PassengerType;
use App\Domain\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $booking_id
 * @property int $tenant_id
 * @property int|null $traveler_id
 * @property PassengerType $type
 * @property string|null $title
 * @property string $first_name
 * @property string $last_name
 * @property string $gender
 * @property Carbon $date_of_birth
 * @property string $nationality
 * @property string $document_type
 * @property string $document_number
 * @property Carbon $document_expiry
 * @property string $document_country
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $ticket_number
 */
class BookingPassenger extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected $hidden = ['document_number'];

    protected function casts(): array
    {
        return [
            'type' => PassengerType::class,
            'date_of_birth' => 'date',
            'document_expiry' => 'date',
            'document_number' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function fullName(): string
    {
        return trim(($this->title ? $this->title.' ' : '').$this->first_name.' '.$this->last_name);
    }

    /** e.g. "****4821" - documents are never returned in full by the API. */
    public function maskedDocument(): string
    {
        $number = (string) $this->document_number;

        return str_repeat('*', max(0, strlen($number) - 4)).substr($number, -4);
    }
}
