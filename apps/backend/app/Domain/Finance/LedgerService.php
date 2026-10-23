<?php

namespace App\Domain\Finance;

use App\Domain\Finance\Data\LedgerLine;
use App\Domain\Finance\Models\LedgerAccount;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\LedgerTransaction;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Tenancy\TenantScope;
use Brick\Math\BigDecimal;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * The only writer of ledger rows. Every posting:
 *  - runs inside the caller's database transaction (asserted);
 *  - locks the affected accounts in id order (deadlock-free) before reading balances;
 *  - must balance (sum of debits == sum of credits) in a single currency;
 *  - is idempotent when an idempotency key is supplied.
 * Posted transactions are immutable; corrections are reversal transactions.
 */
class LedgerService
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * @param  list<LedgerLine>  $lines
     * @param  array<string, mixed>  $metadata
     */
    public function post(
        string $type,
        array $lines,
        string $description,
        ?int $tenantId,
        ?Model $reference = null,
        ?User $actor = null,
        array $metadata = [],
        ?string $idempotencyKey = null,
        ?LedgerTransaction $reverses = null,
        ?BigDecimal $fxRate = null,
        ?string $fxSourceCurrency = null,
    ): LedgerTransaction {
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException('Ledger postings must run inside a database transaction.');
        }

        if ($idempotencyKey && ($existing = $this->findByKey($idempotencyKey))) {
            return $existing;
        }

        $lines = array_values(array_filter($lines, fn (LedgerLine $l) => ! $l->amount->isZero()));
        $this->assertBalanced($lines);
        $currency = $lines[0]->amount->currency;

        /** @var array<int, LedgerAccount> $accounts locked, keyed by id */
        $accounts = LedgerAccount::query()->withoutGlobalScope(TenantScope::class)
            ->whereIn('id', array_unique(array_map(fn (LedgerLine $l) => $l->account->id, $lines)))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id')
            ->all();

        $total = array_reduce(
            array_filter($lines, fn (LedgerLine $l) => $l->direction === 'debit'),
            fn (Money $sum, LedgerLine $l) => $sum->plus($l->amount),
            Money::zero($currency),
        );

        $transaction = LedgerTransaction::query()->create([
            'tenant_id' => $tenantId,
            'type' => $type,
            'currency' => $currency,
            'amount' => $total->toDecimal(),
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'reverses_transaction_id' => $reverses?->id,
            'description' => mb_substr($description, 0, 500),
            'fx_rate' => $fxRate ? (string) $fxRate : null,
            'fx_source_currency' => $fxSourceCurrency,
            'metadata' => $metadata ?: null,
            'idempotency_key' => $idempotencyKey,
            'created_by' => $actor?->id,
        ]);

        foreach ($lines as $line) {
            $account = $accounts[$line->account->id];
            if ($account->currency !== $currency) {
                throw new LogicException("Account {$account->id} is in {$account->currency}, posting is in {$currency}.");
            }

            $newBalance = $account->balanceMoney()->plus($account->effectOf($line->direction, $line->amount));
            $account->forceFill(['balance' => $newBalance->toDecimal()])->save();

            LedgerEntry::query()->create([
                'ledger_transaction_id' => $transaction->id,
                'ledger_account_id' => $account->id,
                'tenant_id' => $account->tenant_id,
                'direction' => $line->direction,
                'amount' => $line->amount->toDecimal(),
                'balance_after' => $newBalance->toDecimal(),
            ]);

            // Keep callers' in-memory account in sync with the persisted balance.
            $line->account->setRawAttributes($account->getAttributes(), true);
        }

        return $transaction;
    }

    /** Post the exact mirror image of a transaction. A transaction can be reversed once. */
    public function reverse(LedgerTransaction $original, string $description, ?User $actor = null, ?string $idempotencyKey = null): LedgerTransaction
    {
        $idempotencyKey ??= "reversal:{$original->id}";
        if ($existing = $this->findByKey($idempotencyKey)) {
            return $existing;
        }

        if (LedgerTransaction::query()->withoutGlobalScope(TenantScope::class)->where('reverses_transaction_id', $original->id)->exists()) {
            throw BusinessRuleException::make('ALREADY_REVERSED', __('errors.already_reversed'), 409);
        }
        if ($original->type === LedgerTransaction::TYPE_REVERSAL) {
            throw BusinessRuleException::make('CANNOT_REVERSE_REVERSAL', __('errors.cannot_reverse_reversal'), 409);
        }

        $lines = $original->entries()->withoutGlobalScope(TenantScope::class)->with(['account' => fn ($q) => $q->withoutGlobalScope(TenantScope::class)])->get()
            ->map(fn (LedgerEntry $e) => new LedgerLine(
                $e->account,
                $e->direction === 'debit' ? 'credit' : 'debit',
                Money::of((string) $e->amount, $original->currency),
            ))
            ->all();

        return $this->post(
            type: LedgerTransaction::TYPE_REVERSAL,
            lines: $lines,
            description: $description,
            tenantId: $original->tenant_id,
            reference: $original->reference_type ? $original->reference()->withoutGlobalScopes()->first() : null,
            actor: $actor,
            metadata: ['reversed_type' => $original->type],
            idempotencyKey: $idempotencyKey,
            reverses: $original,
            fxRate: $original->fx_rate ? BigDecimal::of((string) $original->fx_rate) : null,
            fxSourceCurrency: $original->fx_source_currency,
        );
    }

    private function findByKey(string $key): ?LedgerTransaction
    {
        return LedgerTransaction::query()->withoutGlobalScope(TenantScope::class)->where('idempotency_key', $key)->first();
    }

    /** @param list<LedgerLine> $lines */
    private function assertBalanced(array $lines): void
    {
        if (count($lines) < 2) {
            throw new LogicException('A ledger transaction needs at least two non-zero lines.');
        }

        $currency = $lines[0]->amount->currency;
        $debits = Money::zero($currency);
        $credits = Money::zero($currency);

        foreach ($lines as $line) {
            if (! $line->amount->isPositive()) {
                throw new LogicException('Ledger line amounts must be positive.');
            }
            if (! in_array($line->direction, ['debit', 'credit'], true)) {
                throw new LogicException("Invalid direction [{$line->direction}].");
            }
            $line->direction === 'debit' ? $debits = $debits->plus($line->amount) : $credits = $credits->plus($line->amount);
        }

        if (! $debits->equals($credits)) {
            throw new LogicException("Unbalanced ledger transaction: debits {$debits} != credits {$credits}.");
        }
    }
}
