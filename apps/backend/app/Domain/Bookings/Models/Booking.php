<?php

namespace App\Domain\Bookings\Models;

use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Enums\TicketingStatus;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\LedgerTransaction;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Tenancy\BelongsToTenant;
use App\Domain\Ticketing\Models\BookingDocument;
use App\Domain\Ticketing\Models\Ticket;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $reference
 * @property int|null $created_by
 * @property string|null $flight_offer_id
 * @property string $provider_code
 * @property bool $is_test
 * @property string|null $provider_booking_id
 * @property string|null $pnr
 * @property BookingStatus $status
 * @property TicketingStatus|null $ticketing_status
 * @property string $trip_type
 * @property string $cabin
 * @property list<array<string, mixed>> $itinerary
 * @property array<string, mixed>|null $baggage
 * @property array<string, mixed>|null $fare_rules
 * @property bool $refundable
 * @property array{adults: int, children: int, infants: int} $passenger_counts
 * @property string $contact_email
 * @property string|null $contact_phone
 * @property string $currency
 * @property string $supplier_base
 * @property string $supplier_taxes
 * @property string $supplier_total
 * @property string $markup_amount
 * @property string $sale_total
 * @property string $commission_amount
 * @property string $agency_net
 * @property string $platform_profit
 * @property array<string, mixed> $pricing
 * @property string $account_currency
 * @property string|null $fx_rate
 * @property string|null $charged_amount
 * @property string|null $commission_credited
 * @property int|null $charge_transaction_id
 * @property Carbon|null $hold_expires_at
 * @property Carbon|null $ticketing_deadline
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $ticketed_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property string|null $failure_code
 * @property string|null $failure_reason
 * @property string|null $idempotency_key
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use BelongsToTenant, HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'ticketing_status' => TicketingStatus::class,
            'is_test' => 'boolean',
            'refundable' => 'boolean',
            'itinerary' => 'array',
            'baggage' => 'array',
            'fare_rules' => 'array',
            'passenger_counts' => 'array',
            'pricing' => 'array',
            'supplier_base' => 'decimal:2',
            'supplier_taxes' => 'decimal:2',
            'supplier_total' => 'decimal:2',
            'markup_amount' => 'decimal:2',
            'sale_total' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'agency_net' => 'decimal:2',
            'platform_profit' => 'decimal:2',
            'fx_rate' => 'decimal:6',
            'charged_amount' => 'decimal:2',
            'commission_credited' => 'decimal:2',
            'hold_expires_at' => 'datetime',
            'ticketing_deadline' => 'datetime',
            'confirmed_at' => 'datetime',
            'ticketed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function newFactory(): BookingFactory
    {
        return BookingFactory::new();
    }

    /** @return HasMany<BookingPassenger, $this> */
    public function passengers(): HasMany
    {
        return $this->hasMany(BookingPassenger::class)->orderBy('id');
    }

    /** @return HasMany<BookingEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(BookingEvent::class)->orderBy('id');
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return HasMany<BookingDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(BookingDocument::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withoutGlobalScopes();
    }

    /** @return BelongsTo<LedgerTransaction, $this> */
    public function chargeTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'charge_transaction_id');
    }

    public function saleTotal(): Money
    {
        return Money::of((string) $this->sale_total, $this->currency);
    }

    public function supplierTotal(): Money
    {
        return Money::of((string) $this->supplier_total, $this->currency);
    }

    public function commission(): Money
    {
        return Money::of((string) $this->commission_amount, $this->currency);
    }

    public function agencyNet(): Money
    {
        return Money::of((string) $this->agency_net, $this->currency);
    }

    public function isHoldExpired(): bool
    {
        return $this->hold_expires_at !== null && $this->hold_expires_at->isPast();
    }

    public function route(): string
    {
        return collect($this->itinerary)
            ->map(fn (array $slice) => $slice['origin'].'-'.$slice['destination'])
            ->implode(' / ');
    }

    public function firstDeparture(): ?Carbon
    {
        $at = $this->itinerary[0]['departure_at'] ?? null;

        return $at ? Carbon::parse($at) : null;
    }
}
