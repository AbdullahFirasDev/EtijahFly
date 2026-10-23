<?php

namespace App\Domain\Finance;

use App\Domain\Finance\Models\LedgerAccount;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Tenancy\TenantScope;
use Illuminate\Support\Carbon;

/** Statement of account for an agency wallet, with opening/closing balances. */
class StatementService
{
    /** @return array{account: LedgerAccount, from: Carbon, to: Carbon, opening: Money, closing: Money, total_debits: Money, total_credits: Money, entries: list<array<string, mixed>>} */
    public function forAccount(LedgerAccount $account, Carbon $from, Carbon $to, int $limit = 1000): array
    {
        $base = LedgerEntry::query()->withoutGlobalScope(TenantScope::class)->where('ledger_account_id', $account->id);

        $openingEntry = (clone $base)->where('created_at', '<', $from)->orderByDesc('id')->first();
        $opening = Money::of((string) ($openingEntry->balance_after ?? 0), $account->currency);

        $entries = (clone $base)
            ->whereBetween('created_at', [$from, $to])
            ->with(['transaction' => fn ($q) => $q->withoutGlobalScope(TenantScope::class)])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $debits = Money::zero($account->currency);
        $credits = Money::zero($account->currency);
        $rows = [];
        foreach ($entries as $entry) {
            $amount = Money::of((string) $entry->amount, $account->currency);
            $entry->direction === 'debit' ? $debits = $debits->plus($amount) : $credits = $credits->plus($amount);
            $rows[] = [
                'id' => $entry->id,
                'date' => $entry->created_at->toIso8601String(),
                'type' => $entry->transaction->type,
                'description' => $entry->transaction->description,
                'reference_type' => $entry->transaction->reference_type,
                'reference_id' => $entry->transaction->reference_id,
                'transaction_id' => $entry->ledger_transaction_id,
                'debit' => $entry->direction === 'debit' ? $amount->toDecimal() : null,
                'credit' => $entry->direction === 'credit' ? $amount->toDecimal() : null,
                'balance' => (string) $entry->balance_after,
            ];
        }

        $closing = $entries->isNotEmpty()
            ? Money::of((string) $entries->last()->balance_after, $account->currency)
            : $opening;

        return [
            'account' => $account,
            'from' => $from,
            'to' => $to,
            'opening' => $opening,
            'closing' => $closing,
            'total_debits' => $debits,
            'total_credits' => $credits,
            'entries' => $rows,
        ];
    }
}
