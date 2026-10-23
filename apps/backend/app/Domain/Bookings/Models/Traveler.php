<?php

namespace App\Domain\Bookings\Models;

use App\Domain\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Saved traveller profile of an agency (re-used when booking).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $created_by
 * @property string|null $title
 * @property string $first_name
 * @property string $last_name
 * @property string|null $gender
 * @property Carbon|null $date_of_birth
 * @property string|null $nationality
 * @property string $document_type
 * @property string|null $document_number
 * @property Carbon|null $document_expiry
 * @property string|null $document_country
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $notes
 */
class Traveler extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'title', 'first_name', 'last_name', 'gender', 'date_of_birth', 'nationality', 'document_type',
        'document_number', 'document_expiry', 'document_country', 'email', 'phone', 'notes', 'created_by',
    ];

    protected $hidden = ['document_number'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'document_expiry' => 'date',
            'document_number' => 'encrypted',
        ];
    }

    public function maskedDocument(): ?string
    {
        if (! $this->document_number) {
            return null;
        }
        $number = (string) $this->document_number;

        return str_repeat('*', max(0, strlen($number) - 4)).substr($number, -4);
    }
}
