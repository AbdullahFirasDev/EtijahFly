<?php

namespace App\Domain\Finance;

use App\Domain\Bookings\Models\Booking;
use App\Domain\Bookings\Models\Refund;
use App\Domain\Commissions\CurrencyConverter;
use App\Domain\Finance\Data\LedgerLine;
use App\Domain\Finance\Models\LedgerAccount;
use App\Domain\Finance\Models\LedgerTransaction;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Tenancy\TenantScope;
use App\Domain\Tenancy\Models\Tenant;
use Brick\Math\BigDecimal;

/**
 * Accounting for the booking lifecycle. All methods must be called inside a database
 * transaction that also holds a lock on the booking row.
 *
 * Booking charge (account currency, converted at the rate recorded on the booking):
 *   DR Agency wallet        sale total
 *   CR Supplier payable     supplier total
 *   CR Markup revenue       markup
 *   DR Commission expense   commission
 *   CR Agency wallet        commission
 *
 * Refund after ticketing (provider refund converted at the ORIGINAL booking rate, so the
 * agency carries no FX risk; markup is retained as a non-refundable service fee):
 *   DR Supplier payable     provider refund
 *   CR Agency wallet        provider refund - platform refund fee
 *   CR Refund fee revenue   platform refund fee
 *   DR Agency wallet        commission (claw-back)
 *   CR Commission expense   commission
 */
class BookingLedger
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AccountService $accounts,
        private readonly CurrencyConverter $fx,
        private readonly InvoiceService $invoices,
    ) {}

    public function charge(Booking $booking, ?User $actor): LedgerTransaction
    {
        $tenant = Tenant::query()->findOrFail($booking->tenant_id);
        $wallet = $this->accounts->lockWallet($this->accounts->walletFor($tenant, $booking->account_currency));
        $currency = $wallet->currency;

        $rate = $this->fx->rate($booking->currency, $currency);
        $sale = $this->fx->convert($booking->saleTotal(), $currency, $rate);
        $supplier = $this->fx->convert($booking->supplierTotal(), $currency, $rate);
        $markup = $sale->minus($supplier);
        $commission = $this->fx->convert($booking->commission(), $currency, $rate)->min($markup);

        $this->accounts->assertCanSpend($wallet, $sale->minus($commission));

        $transaction = $this->ledger->post(
            type: LedgerTransaction::TYPE_BOOKING_CHARGE,
            lines: [
                LedgerLine::debit($wallet, $sale),
                LedgerLine::credit($this->accounts->system(LedgerAccount::SUPPLIER_PAYABLE, $currency), $supplier),
                LedgerLine::credit($this->accounts->system(LedgerAccount::MARKUP_REVENUE, $currency), $markup),
                LedgerLine::debit($this->accounts->system(LedgerAccount::COMMISSION_EXPENSE, $currency), $commission),
                LedgerLine::credit($wallet, $commission),
            ],
            description: "Booking {$booking->reference} ({$booking->route()})",
            tenantId: $booking->tenant_id,
            reference: $booking,
            actor: $actor,
            metadata: [
                'sale' => $sale->toDecimal(), 'supplier' => $supplier->toDecimal(),
                'markup' => $markup->toDecimal(), 'commission' => $commission->toDecimal(),
            ],
            idempotencyKey: "booking:{$booking->id}:charge",
            fxRate: $rate,
            fxSourceCurrency: $booking->currency,
        );

        $booking->forceFill([
            'account_currency' => $currency,
            'fx_rate' => (string) $rate,
            'charged_amount' => $sale->toDecimal(),
            'commission_credited' => $commission->toDecimal(),
            'charge_transaction_id' => $transaction->id,
        ]);

        $this->invoices->issueForBooking($booking, $transaction, $sale, $commission);

        return $transaction;
    }

    /** Full reversal of the booking charge (cancellation before ticketing, ticketing failure). */
    public function reverseCharge(Booking $booking, string $reason, ?User $actor): ?LedgerTransaction
    {
        if (! $booking->charge_transaction_id) {
            return null;
        }

        $charge = LedgerTransaction::query()->withoutGlobalScope(TenantScope::class)->findOrFail($booking->charge_transaction_id);
        $reversal = $this->ledger->reverse($charge, "Reversal of {$booking->reference}: {$reason}", $actor, "booking:{$booking->id}:charge:reversal");

        $charged = Money::of((string) $booking->charged_amount, $booking->account_currency);
        $commission = Money::of((string) ($booking->commission_credited ?? 0), $booking->account_currency);

        $this->invoices->creditNote($booking, $reversal, $charged->minus($commission), [
            ['description' => __('finance.credit_note_line_reversal', ['reference' => $booking->reference, 'reason' => $reason]), 'amount' => $charged->minus($commission)->toDecimal()],
        ]);

        return $reversal;
    }

    /** @return array{transaction: LedgerTransaction|null, agency_credit: Money, fee: Money, clawback: Money} */
    public function refund(Booking $booking, Refund $refund, Money $providerRefund, ?User $actor): array
    {
        $tenant = Tenant::query()->findOrFail($booking->tenant_id);
        $wallet = $this->accounts->lockWallet($this->accounts->walletFor($tenant, $booking->account_currency));
        $currency = $wallet->currency;

        $rate = BigDecimal::of((string) ($booking->fx_rate ?? 1));
        $refundAmount = $this->fx->convert($providerRefund, $currency, $rate);
        $fee = Money::of((string) (config("platform.finance.refund_fee.{$currency}") ?? 0), $currency)->min($refundAmount);
        $agencyCredit = $refundAmount->minus($fee);
        $clawback = Money::of((string) ($booking->commission_credited ?? 0), $currency);

        $lines = [
            LedgerLine::debit($this->accounts->system(LedgerAccount::SUPPLIER_PAYABLE, $currency), $refundAmount),
            LedgerLine::credit($wallet, $agencyCredit),
            LedgerLine::credit($this->accounts->system(LedgerAccount::REFUND_FEE_REVENUE, $currency), $fee),
            LedgerLine::debit($wallet, $clawback),
            LedgerLine::credit($this->accounts->system(LedgerAccount::COMMISSION_EXPENSE, $currency), $clawback),
        ];

        if ($refundAmount->isZero() && $clawback->isZero()) {
            return ['transaction' => null, 'agency_credit' => $agencyCredit, 'fee' => $fee, 'clawback' => $clawback];
        }

        $transaction = $this->ledger->post(
            type: LedgerTransaction::TYPE_REFUND,
            lines: $lines,
            description: "Refund {$booking->reference}",
            tenantId: $booking->tenant_id,
            reference: $refund,
            actor: $actor,
            metadata: [
                'provider_refund' => $providerRefund->toDecimal().' '.$providerRefund->currency,
                'fee' => $fee->toDecimal(), 'clawback' => $clawback->toDecimal(),
            ],
            idempotencyKey: "refund:{$refund->id}",
            fxRate: $rate,
            fxSourceCurrency: $providerRefund->currency,
        );

        $net = $agencyCredit->minus($clawback);
        $this->invoices->creditNote($booking, $transaction, $net->max(Money::zero($currency)), array_values(array_filter([
            ['description' => __('finance.credit_note_line_refund', ['reference' => $booking->reference]), 'amount' => $agencyCredit->toDecimal()],
            $clawback->isPositive() ? ['description' => __('finance.credit_note_line_clawback'), 'amount' => $clawback->negated()->toDecimal()] : null,
        ])));

        return ['transaction' => $transaction, 'agency_credit' => $agencyCredit, 'fee' => $fee, 'clawback' => $clawback];
    }
}
