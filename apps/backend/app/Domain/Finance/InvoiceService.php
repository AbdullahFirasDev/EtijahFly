<?php

namespace App\Domain\Finance;

use App\Domain\Bookings\Models\Booking;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\LedgerTransaction;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Tenancy\TenantScope;
use App\Domain\Ticketing\Jobs\GenerateInvoiceDocument;

class InvoiceService
{
    public function __construct(private readonly DocumentNumberGenerator $numbers) {}

    public function issueForBooking(Booking $booking, LedgerTransaction $transaction, Money $sale, Money $commission): Invoice
    {
        $lines = [[
            'description' => __('finance.invoice_line_ticket', ['reference' => $booking->reference, 'route' => $booking->route()]),
            'amount' => $sale->toDecimal(),
        ]];
        if ($commission->isPositive()) {
            $lines[] = ['description' => __('finance.invoice_line_commission'), 'amount' => $commission->negated()->toDecimal()];
        }

        return $this->create(Invoice::TYPE_INVOICE, $booking, $transaction, $sale, $sale->minus($commission), $lines);
    }

    /** @param list<array{description: string, amount: string}> $lines */
    public function creditNote(Booking $booking, LedgerTransaction $transaction, Money $total, array $lines): Invoice
    {
        $related = Invoice::query()->withoutGlobalScope(TenantScope::class)
            ->where('booking_id', $booking->id)->where('type', Invoice::TYPE_INVOICE)->latest('id')->first();

        $invoice = $this->create(Invoice::TYPE_CREDIT_NOTE, $booking, $transaction, $total, $total, $lines, $related);

        if ($related) {
            $related->forceFill(['status' => 'credited'])->save();
        }

        return $invoice;
    }

    /** @param list<array{description: string, amount: string}> $lines */
    private function create(string $type, Booking $booking, LedgerTransaction $transaction, Money $subtotal, Money $total, array $lines, ?Invoice $related = null): Invoice
    {
        $invoice = Invoice::query()->create([
            'tenant_id' => $booking->tenant_id,
            'number' => $this->numbers->next($type, $type === Invoice::TYPE_INVOICE ? 'INV' : 'CN'),
            'type' => $type,
            'booking_id' => $booking->id,
            'related_invoice_id' => $related?->id,
            'ledger_transaction_id' => $transaction->id,
            'currency' => $total->currency,
            'subtotal' => $subtotal->toDecimal(),
            'total' => $total->toDecimal(),
            'lines' => $lines,
            'status' => 'issued',
            'issued_at' => now(),
        ]);

        GenerateInvoiceDocument::dispatch($invoice->id)->afterCommit();

        return $invoice;
    }
}
