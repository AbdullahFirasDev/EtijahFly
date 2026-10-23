<?php

use App\Domain\AuditLog\Models\AuditLog;
use App\Domain\Finance\Models\FinancialAdjustment;
use App\Domain\Payments\Models\Payment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Notification::fake());

it('credits an agency payment only after platform approval', function () {
    $tenant = agency('IQD', '0');
    actingAsPartner(agencyUser($tenant, 'accountant'));

    $this->withHeader('Idempotency-Key', 'pay-'.uniqid())->post('/api/v1/partner/finance/payments', [
        'amount' => '2500000', 'currency' => 'IQD', 'method' => 'bank_transfer', 'paid_at' => now()->toDateString(),
        'external_reference' => 'RAF-TRX-1', 'proof' => UploadedFile::fake()->create('receipt.pdf', 120, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.has_proof', true);

    $payment = Payment::query()->sole();
    Storage::disk('local')->assertExists('payment-proofs/'.$payment->proof_path);
    expect(wallet($tenant)->balance)->toBe('0.00');

    actingAsAdmin(platformUser('platform_finance'));
    $this->postJson("/api/v1/admin/finance/payments/{$payment->id}/approve", ['notes' => 'Matched bank statement'])->assertOk()->assertJsonPath('data.status', 'approved');
    expect(wallet($tenant)->balance)->toBe('2500000.00');

    $this->postJson("/api/v1/admin/finance/payments/{$payment->id}/approve")->assertStatus(409)->assertJsonPath('code', 'PAYMENT_ALREADY_REVIEWED');
    expect(wallet($tenant)->balance)->toBe('2500000.00');
    assertLedgerBalanced();
});

it('rejects payments without touching the balance', function () {
    $tenant = agency('USD', '0');
    actingAsPartner(agencyUser($tenant, 'accountant'));
    $id = $this->withHeader('Idempotency-Key', 'pay-'.uniqid())->postJson('/api/v1/partner/finance/payments', [
        'amount' => '100', 'currency' => 'USD', 'method' => 'cash', 'paid_at' => now()->toDateString(),
    ])->json('data.id');

    actingAsAdmin(platformUser('platform_finance'));
    $this->postJson("/api/v1/admin/finance/payments/{$id}/reject", ['notes' => 'Not received'])->assertOk()->assertJsonPath('data.status', 'rejected');
    expect(wallet($tenant)->balance)->toBe('0.00');
});

it('requires four-eyes approval for manual adjustments', function () {
    $tenant = agency('USD', '1000.00');
    $requester = actingAsAdmin(platformUser('platform_staff'));

    $id = $this->postJson('/api/v1/admin/finance/adjustments', [
        'tenant_id' => $tenant->id, 'direction' => 'debit', 'amount' => '75.50', 'currency' => 'USD', 'reason' => 'Bank charges on incoming transfer',
    ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
    expect(wallet($tenant)->balance)->toBe('1000.00');

    // platform staff may request but not approve
    $this->postJson("/api/v1/admin/finance/adjustments/{$id}/approve")->assertForbidden();

    // the same person can never approve their own request, even with the permission
    $requester->assignRole('platform_finance');
    $this->postJson("/api/v1/admin/finance/adjustments/{$id}/approve")->assertStatus(403)->assertJsonPath('code', 'FOUR_EYES_REQUIRED');

    actingAsAdmin(platformUser('platform_finance'));
    $this->postJson("/api/v1/admin/finance/adjustments/{$id}/approve", ['notes' => 'ok'])->assertOk()->assertJsonPath('data.status', 'approved');

    expect(wallet($tenant)->balance)->toBe('924.50')
        ->and(FinancialAdjustment::query()->sole()->ledger_transaction_id)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'finance.adjustment_approved')->exists())->toBeTrue();
    assertLedgerBalanced();
});

it('restricts credit limit changes to credit managers and audits them', function () {
    $tenant = agency('USD', '0');

    actingAsAdmin(platformUser('platform_staff'));
    $this->putJson("/api/v1/admin/tenants/{$tenant->id}/credit-limit", ['currency' => 'USD', 'credit_limit' => '5000', 'reason' => 'x'])->assertForbidden();

    actingAsAdmin(platformUser('platform_finance'));
    $this->putJson("/api/v1/admin/tenants/{$tenant->id}/credit-limit", ['currency' => 'USD', 'credit_limit' => '5000', 'reason' => 'Annual review'])
        ->assertOk()->assertJsonPath('data.credit_limit', '5000.00')->assertJsonPath('data.available', '5000.00');

    $log = AuditLog::query()->where('action', 'finance.credit_limit_changed')->sole();
    expect($log->old_values['credit_limit'])->toBe('0.00')->and($log->new_values['credit_limit'])->toBe('5000.00');
});

it('blocks charges on a frozen account', function () {
    $tenant = agency('USD', '5000.00');
    actingAsAdmin(platformUser('platform_finance'));
    $this->putJson("/api/v1/admin/tenants/{$tenant->id}/account-status", ['currency' => 'USD', 'status' => 'frozen', 'reason' => 'Overdue'])->assertOk();

    actingAsPartner(agencyUser($tenant, 'booking_agent'));
    createBooking(firstOffer())->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_FROZEN');
});
