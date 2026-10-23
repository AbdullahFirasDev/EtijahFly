<?php

namespace App\Domain\Payments;

use App\Domain\AuditLog\Auditor;
use App\Domain\Finance\AccountService;
use App\Domain\Finance\Data\LedgerLine;
use App\Domain\Finance\LedgerService;
use App\Domain\Finance\Models\LedgerAccount;
use App\Domain\Finance\Models\LedgerTransaction;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\BookingNotifier;
use App\Domain\Payments\Models\Payment;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Storage\FileStorage;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly LedgerService $ledger,
        private readonly FileStorage $storage,
        private readonly Auditor $auditor,
        private readonly BookingNotifier $notifier,
        private readonly ManualTransferGateway $gateway,
    ) {}

    /** @param array{amount: string, currency: string, method: string, paid_at: string, external_reference?: string|null, notes?: string|null} $data */
    public function submit(User $user, array $data, ?UploadedFile $proof, ?string $idempotencyKey): Payment
    {
        if ($idempotencyKey && ($existing = Payment::query()->where('tenant_id', $user->tenant_id)->where('idempotency_key', $idempotencyKey)->first())) {
            return $existing;
        }

        $tenant = Tenant::query()->findOrFail($user->tenant_id);
        $wallet = $this->accounts->walletFor($tenant, $data['currency']);
        $amount = Money::of($data['amount'], $data['currency']);
        if (! $amount->isPositive()) {
            throw BusinessRuleException::make('INVALID_AMOUNT', __('errors.invalid_amount'), 422);
        }

        $proofPath = null;
        if ($proof) {
            $proofPath = sprintf('%d/%s.%s', $tenant->id, Str::ulid(), $proof->guessExtension() ?: 'bin');
            $this->storage->put(config('filestorage.buckets.payment_proofs'), $proofPath, $proof->getContent(), $proof->getMimeType() ?: 'application/octet-stream');
        }

        $payment = Payment::query()->create([
            'tenant_id' => $tenant->id,
            'ledger_account_id' => $wallet->id,
            'reference' => 'PAY-'.Str::upper(Str::random(10)),
            'amount' => $amount->toDecimal(),
            'currency' => $amount->currency,
            'method' => $data['method'],
            'gateway' => $this->gateway->code(),
            'external_reference' => $data['external_reference'] ?? null,
            'paid_at' => $data['paid_at'],
            'proof_path' => $proofPath,
            'status' => 'pending',
            'notes' => $data['notes'] ?? null,
            'submitted_by' => $user->id,
            'idempotency_key' => $idempotencyKey,
        ]);

        $this->auditor->record('payment.submitted', $payment, new: ['amount' => $payment->amount, 'currency' => $payment->currency, 'method' => $payment->method]);

        return $payment;
    }

    public function approve(Payment $payment, User $reviewer, ?string $notes): Payment
    {
        $payment = DB::transaction(function () use ($payment, $reviewer, $notes) {
            $locked = Payment::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($payment->id);
            $this->assertPending($locked, $reviewer);

            $wallet = LedgerAccount::query()->withoutGlobalScopes()->findOrFail($locked->ledger_account_id);
            $amount = Money::of((string) $locked->amount, $locked->currency);

            $transaction = $this->ledger->post(
                type: LedgerTransaction::TYPE_PAYMENT,
                lines: [
                    LedgerLine::debit($this->accounts->system(LedgerAccount::CASH_BANK, $locked->currency), $amount),
                    LedgerLine::credit($wallet, $amount),
                ],
                description: "Payment {$locked->reference} ({$locked->method})",
                tenantId: $locked->tenant_id,
                reference: $locked,
                actor: $reviewer,
                metadata: ['external_reference' => $locked->external_reference],
                idempotencyKey: "payment:{$locked->id}",
            );

            $locked->forceFill([
                'status' => 'approved',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
                'ledger_transaction_id' => $transaction->id,
            ])->save();

            return $locked;
        });

        $this->auditor->record('payment.approved', $payment, new: ['amount' => $payment->amount, 'currency' => $payment->currency], tenantId: $payment->tenant_id);
        $this->notifier->paymentReviewed($payment);

        return $payment;
    }

    public function reject(Payment $payment, User $reviewer, string $notes): Payment
    {
        $payment = DB::transaction(function () use ($payment, $reviewer, $notes) {
            $locked = Payment::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($payment->id);
            $this->assertPending($locked, $reviewer);
            $locked->forceFill(['status' => 'rejected', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_notes' => $notes])->save();

            return $locked;
        });

        $this->auditor->record('payment.rejected', $payment, new: ['notes' => $notes], tenantId: $payment->tenant_id);
        $this->notifier->paymentReviewed($payment);

        return $payment;
    }

    public function proofUrl(Payment $payment): ?string
    {
        return $payment->proof_path
            ? $this->storage->temporaryUrl(config('filestorage.buckets.payment_proofs'), $payment->proof_path, (int) config('filestorage.signed_url_ttl'))
            : null;
    }

    private function assertPending(Payment $payment, User $reviewer): void
    {
        if ($payment->status !== 'pending') {
            throw BusinessRuleException::make('PAYMENT_ALREADY_REVIEWED', __('errors.payment_already_reviewed'), 409);
        }
        if ($payment->submitted_by === $reviewer->id) {
            throw BusinessRuleException::make('FOUR_EYES_REQUIRED', __('errors.four_eyes_required'), 403);
        }
    }
}
