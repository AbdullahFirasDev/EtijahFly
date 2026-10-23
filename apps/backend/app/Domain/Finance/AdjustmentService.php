<?php

namespace App\Domain\Finance;

use App\Domain\AuditLog\Auditor;
use App\Domain\Finance\Data\LedgerLine;
use App\Domain\Finance\Models\FinancialAdjustment;
use App\Domain\Finance\Models\LedgerAccount;
use App\Domain\Finance\Models\LedgerTransaction;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Shared\Money\Money;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Manual balance corrections with four-eyes approval: the approver must be a different
 * user holding platform.adjustments.approve. Nothing touches the ledger until approval.
 */
class AdjustmentService
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly LedgerService $ledger,
        private readonly Auditor $auditor,
    ) {}

    public function request(User $requester, Tenant $tenant, string $direction, Money $amount, string $reason): FinancialAdjustment
    {
        if (! in_array($direction, ['credit', 'debit'], true) || ! $amount->isPositive()) {
            throw BusinessRuleException::make('INVALID_ADJUSTMENT', __('errors.invalid_adjustment'), 422);
        }

        $wallet = $this->accounts->walletFor($tenant, $amount->currency);

        $adjustment = FinancialAdjustment::query()->create([
            'tenant_id' => $tenant->id,
            'ledger_account_id' => $wallet->id,
            'direction' => $direction,
            'amount' => $amount->toDecimal(),
            'currency' => $amount->currency,
            'reason' => $reason,
            'status' => 'pending',
            'requested_by' => $requester->id,
        ]);

        $this->auditor->record('finance.adjustment_requested', $adjustment, new: [
            'direction' => $direction, 'amount' => $amount->toDecimal(), 'currency' => $amount->currency, 'reason' => $reason,
        ], tenantId: $tenant->id);

        return $adjustment;
    }

    public function approve(FinancialAdjustment $adjustment, User $approver, ?string $notes): FinancialAdjustment
    {
        $adjustment = DB::transaction(function () use ($adjustment, $approver, $notes) {
            $locked = FinancialAdjustment::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($adjustment->id);
            $this->assertReviewable($locked, $approver);

            $wallet = LedgerAccount::query()->withoutGlobalScopes()->findOrFail($locked->ledger_account_id);
            $counterpart = $this->accounts->system(LedgerAccount::ADJUSTMENTS, $locked->currency);
            $amount = Money::of((string) $locked->amount, $locked->currency);

            $lines = $locked->direction === 'credit'
                ? [LedgerLine::debit($counterpart, $amount), LedgerLine::credit($wallet, $amount)]
                : [LedgerLine::debit($wallet, $amount), LedgerLine::credit($counterpart, $amount)];

            $transaction = $this->ledger->post(
                type: LedgerTransaction::TYPE_ADJUSTMENT,
                lines: $lines,
                description: "Adjustment #{$locked->id}: {$locked->reason}",
                tenantId: $locked->tenant_id,
                reference: $locked,
                actor: $approver,
                metadata: ['requested_by' => $locked->requested_by],
                idempotencyKey: "adjustment:{$locked->id}",
            );

            $locked->forceFill([
                'status' => 'approved',
                'reviewed_by' => $approver->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
                'ledger_transaction_id' => $transaction->id,
            ])->save();

            return $locked;
        });

        $this->auditor->record('finance.adjustment_approved', $adjustment, new: ['amount' => $adjustment->amount, 'direction' => $adjustment->direction], tenantId: $adjustment->tenant_id);

        return $adjustment;
    }

    public function reject(FinancialAdjustment $adjustment, User $approver, string $notes): FinancialAdjustment
    {
        $adjustment = DB::transaction(function () use ($adjustment, $approver, $notes) {
            $locked = FinancialAdjustment::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($adjustment->id);
            $this->assertReviewable($locked, $approver);
            $locked->forceFill(['status' => 'rejected', 'reviewed_by' => $approver->id, 'reviewed_at' => now(), 'review_notes' => $notes])->save();

            return $locked;
        });

        $this->auditor->record('finance.adjustment_rejected', $adjustment, new: ['notes' => $notes], tenantId: $adjustment->tenant_id);

        return $adjustment;
    }

    private function assertReviewable(FinancialAdjustment $adjustment, User $approver): void
    {
        if ($adjustment->status !== 'pending') {
            throw BusinessRuleException::make('ADJUSTMENT_ALREADY_REVIEWED', __('errors.adjustment_already_reviewed'), 409);
        }
        if ($adjustment->requested_by === $approver->id) {
            throw BusinessRuleException::make('FOUR_EYES_REQUIRED', __('errors.four_eyes_required'), 403);
        }
    }
}
