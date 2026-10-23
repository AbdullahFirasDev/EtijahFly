<?php

use App\Domain\Finance\AccountService;
use App\Domain\Finance\Data\LedgerLine;
use App\Domain\Finance\LedgerService;
use App\Domain\Finance\Models\LedgerAccount;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\LedgerTransaction;
use App\Domain\Finance\StatementService;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Shared\Money\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function post(LedgerAccount $wallet, string $amount, string $direction = 'credit', ?string $key = null): LedgerTransaction
{
    $cash = app(AccountService::class)->system(LedgerAccount::CASH_BANK, $wallet->currency);
    $money = Money::of($amount, $wallet->currency);
    $lines = $direction === 'credit'
        ? [LedgerLine::debit($cash, $money), LedgerLine::credit($wallet, $money)]
        : [LedgerLine::debit($wallet, $money), LedgerLine::credit($cash, $money)];

    return DB::transaction(fn () => app(LedgerService::class)->post('payment', $lines, 'test', $wallet->tenant_id, idempotencyKey: $key));
}

it('keeps account balances in sync with balanced double entries', function () {
    $tenant = agency('USD', '0');
    $wallet = wallet($tenant);

    post($wallet, '150.00');
    post($wallet, '40.25', 'debit');

    expect(wallet($tenant)->balance)->toBe('109.75')
        ->and((string) LedgerEntry::query()->where('ledger_account_id', $wallet->id)->latest('id')->value('balance_after'))->toBe('109.75');
    assertLedgerBalanced();
});

it('rejects unbalanced postings', function () {
    $wallet = wallet(agency('USD', '0'));
    $cash = app(AccountService::class)->system(LedgerAccount::CASH_BANK, 'USD');

    expect(fn () => DB::transaction(fn () => app(LedgerService::class)->post('payment', [
        LedgerLine::debit($cash, Money::of('10', 'USD')),
        LedgerLine::credit($wallet, Money::of('9', 'USD')),
    ], 'unbalanced', $wallet->tenant_id)))->toThrow(LogicException::class, 'Unbalanced');

    expect(fn () => DB::transaction(fn () => app(LedgerService::class)->post('payment', [
        LedgerLine::debit($cash, Money::of('10', 'USD')),
        LedgerLine::credit($wallet, Money::of('-10', 'USD')),
    ], 'negative', $wallet->tenant_id)))->toThrow(LogicException::class);
});

it('is idempotent per key', function () {
    $wallet = wallet(agency('USD', '0'));

    $a = post($wallet, '100', key: 'payment:42');
    $b = post($wallet, '100', key: 'payment:42');

    expect($a->id)->toBe($b->id)->and(wallet($wallet->tenant)->balance)->toBe('100.00');
});

it('never updates or deletes posted entries - enforced by the database itself', function () {
    $wallet = wallet(agency('USD', '0'));
    $tx = post($wallet, '100');

    // Each attempt runs in its own savepoint: PostgreSQL aborts the transaction on error.
    expect(fn () => DB::transaction(fn () => DB::table('ledger_entries')->where('ledger_transaction_id', $tx->id)->update(['amount' => 1])))->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::transaction(fn () => DB::table('ledger_transactions')->where('id', $tx->id)->delete()))->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::transaction(fn () => DB::statement('TRUNCATE ledger_entries CASCADE')))->toThrow(QueryException::class, 'append-only');
    expect(LedgerEntry::query()->where('ledger_transaction_id', $tx->id)->sum('amount'))->toEqual(200);
});

it('corrects mistakes with a reversal that can only happen once', function () {
    $tenant = agency('USD', '0');
    $tx = post(wallet($tenant), '80');

    $reversal = DB::transaction(fn () => app(LedgerService::class)->reverse($tx, 'entered twice'));
    expect($reversal->reverses_transaction_id)->toBe($tx->id)->and(wallet($tenant)->balance)->toBe('0.00');

    // same idempotency key returns the same reversal; a fresh reversal attempt is refused
    expect(DB::transaction(fn () => app(LedgerService::class)->reverse($tx, 'again'))->id)->toBe($reversal->id);
    expect(fn () => DB::transaction(fn () => app(LedgerService::class)->reverse($tx, 'again', idempotencyKey: 'other')))->toThrow(BusinessRuleException::class);
    assertLedgerBalanced();
});

it('produces a statement with opening and closing balances', function () {
    $tenant = agency('USD', '0');
    $wallet = wallet($tenant);
    $this->travel(-10)->days();
    post($wallet, '500');
    $this->travelBack();
    post($wallet, '120', 'debit');
    post($wallet, '30');

    $statement = app(StatementService::class)->forAccount($wallet->refresh(), now()->subDay(), now()->addMinute());

    expect($statement['opening']->toDecimal())->toBe('500.00')
        ->and($statement['closing']->toDecimal())->toBe('410.00')
        ->and($statement['total_debits']->toDecimal())->toBe('120.00')
        ->and($statement['total_credits']->toDecimal())->toBe('30.00')
        ->and($statement['entries'])->toHaveCount(2);
});

it('exposes the statement to the agency accountant, as JSON and CSV', function () {
    $tenant = agency('USD', '250.00');
    actingAsPartner(agencyUser($tenant, 'accountant'));

    $this->getJson('/api/v1/partner/finance/statement')->assertOk()->assertJsonPath('closing_balance', '250.00');
    $csv = $this->get('/api/v1/partner/finance/statement?format=csv')->assertOk()->streamedContent();
    expect($csv)->toContain('date,type,description,debit,credit,balance')->toContain('250.00');
});
