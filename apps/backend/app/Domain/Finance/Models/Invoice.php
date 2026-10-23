<?php

namespace App\Domain\Finance\Models;

use App\Domain\Bookings\Models\Booking;
use App\Domain\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $number
 * @property string $type invoice | credit_note
 * @property int|null $booking_id
 * @property int|null $related_invoice_id
 * @property int|null $ledger_transaction_id
 * @property string $currency
 * @property string $subtotal
 * @property string $total
 * @property list<array{description: string, amount: string}> $lines
 * @property string $status
 * @property Carbon $issued_at
 * @property string|null $pdf_path
 */
class Invoice extends Model
{
    use BelongsToTenant;

    public const TYPE_INVOICE = 'invoice';

    public const TYPE_CREDIT_NOTE = 'credit_note';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
            'lines' => 'array',
            'issued_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function relatedInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_invoice_id');
    }
}
