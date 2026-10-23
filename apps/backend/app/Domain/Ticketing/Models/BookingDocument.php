<?php

namespace App\Domain\Ticketing\Models;

use App\Domain\Bookings\Models\Booking;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $booking_id
 * @property int|null $invoice_id
 * @property string $type booking_confirmation | e_ticket | invoice | credit_note
 * @property string $path
 * @property string $filename
 * @property int $size
 * @property string $sha256
 * @property string|null $booking_status
 * @property int|null $generated_by
 */
class BookingDocument extends Model
{
    use BelongsToTenant;

    public const TYPE_CONFIRMATION = 'booking_confirmation';

    public const TYPE_E_TICKET = 'e_ticket';

    public const TYPE_INVOICE = 'invoice';

    public const TYPE_CREDIT_NOTE = 'credit_note';

    protected $guarded = ['id'];

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
