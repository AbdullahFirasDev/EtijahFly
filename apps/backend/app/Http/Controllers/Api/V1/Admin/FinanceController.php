<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Finance\AdjustmentService;
use App\Domain\Finance\Models\FinancialAdjustment;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\LedgerAccount;
use App\Domain\Finance\Models\LedgerTransaction;
use App\Domain\Finance\StatementService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\PaymentService;
use App\Domain\Shared\Money\Money;
use App\Domain\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdjustmentResource;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\LedgerAccountResource;
use App\Http\Resources\PaymentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class FinanceController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly AdjustmentService $adjustments,
        private readonly StatementService $statements,
    ) {}

    /** Every ledger account: agency wallets and platform accounts. */
    public function accounts(Request $request): JsonResponse
    {
        $this->authorize('platform.finance.view');

        $accounts = LedgerAccount::query()->with('tenant:id,name')
            ->when($request->query('kind') === 'platform', fn ($q) => $q->whereNull('tenant_id'))
            ->when($request->query('kind') === 'agency', fn ($q) => $q->whereNotNull('tenant_id'))
            ->orderByRaw('tenant_id IS NOT NULL')->orderBy('code')->orderBy('currency')
            ->get();

        return response()->json(['data' => $accounts->map(fn (LedgerAccount $a) => (new LedgerAccountResource($a))->resolve() + [
            'name' => $a->name,
            'tenant' => $a->tenant?->only(['id', 'name']),
            'normal_side' => $a->normal_side,
        ])]);
    }

    public function statement(Request $request, LedgerAccount $account): JsonResponse
    {
        $this->authorize('platform.finance.view');
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : now()->startOfMonth();
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay();

        $s = $this->statements->forAccount($account, $from, $to);

        return response()->json([
            'account' => (new LedgerAccountResource($account))->resolve() + ['name' => $account->name],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'opening_balance' => $s['opening']->toDecimal(),
            'closing_balance' => $s['closing']->toDecimal(),
            'total_debits' => $s['total_debits']->toDecimal(),
            'total_credits' => $s['total_credits']->toDecimal(),
            'entries' => $s['entries'],
        ]);
    }

    /** A posted ledger transaction with all its lines (audit drill-down). */
    public function transaction(LedgerTransaction $transaction): JsonResponse
    {
        $this->authorize('platform.finance.view');
        $transaction->load(['entries.account', 'creator:id,name', 'reversal:id,reverses_transaction_id,created_at']);

        return response()->json(['data' => [
            'id' => $transaction->id,
            'type' => $transaction->type,
            'currency' => $transaction->currency,
            'amount' => (string) $transaction->amount,
            'description' => $transaction->description,
            'reference_type' => $transaction->reference_type,
            'reference_id' => $transaction->reference_id,
            'reverses_transaction_id' => $transaction->reverses_transaction_id,
            'reversed_by' => $transaction->reversal?->id,
            'fx_rate' => $transaction->fx_rate,
            'fx_source_currency' => $transaction->fx_source_currency,
            'metadata' => $transaction->metadata,
            'created_by' => $transaction->creator?->only(['id', 'name']),
            'created_at' => $transaction->created_at->toIso8601String(),
            'entries' => $transaction->entries->map(fn ($e) => [
                'account' => ['id' => $e->account->id, 'code' => $e->account->code, 'name' => $e->account->name, 'tenant_id' => $e->account->tenant_id],
                'direction' => $e->direction,
                'amount' => (string) $e->amount,
                'balance_after' => (string) $e->balance_after,
            ]),
        ]]);
    }

    public function payments(Request $request): AnonymousResourceCollection
    {
        $this->authorize('platform.finance.view');

        return PaymentResource::collection(Payment::query()
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('tenant_id'), fn ($q, $id) => $q->where('tenant_id', $id))
            ->with(['tenant:id,name', 'submitter', 'reviewer'])
            ->latest('id')->paginate(25));
    }

    public function paymentProof(Payment $payment): JsonResponse
    {
        $this->authorize('platform.payments.review');

        return response()->json(['url' => $this->payments->proofUrl($payment), 'expires_in' => (int) config('filestorage.signed_url_ttl')]);
    }

    public function approvePayment(Request $request, Payment $payment): PaymentResource
    {
        $this->authorize('platform.payments.review');
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);

        return new PaymentResource($this->payments->approve($payment, $request->user(), $data['notes'] ?? null)->load(['tenant:id,name', 'submitter', 'reviewer']));
    }

    public function rejectPayment(Request $request, Payment $payment): PaymentResource
    {
        $this->authorize('platform.payments.review');
        $data = $request->validate(['notes' => ['required', 'string', 'max:1000']]);

        return new PaymentResource($this->payments->reject($payment, $request->user(), $data['notes'])->load(['tenant:id,name', 'submitter', 'reviewer']));
    }

    public function adjustments(Request $request): AnonymousResourceCollection
    {
        $this->authorize('platform.finance.view');

        return AdjustmentResource::collection(FinancialAdjustment::query()
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->with(['tenant:id,name', 'requester', 'reviewer'])
            ->latest('id')->paginate(25));
    }

    /** Request a manual balance correction (needs approval by another user). */
    public function storeAdjustment(Request $request): JsonResponse
    {
        $this->authorize('platform.adjustments.request');
        $data = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'direction' => ['required', Rule::in(['credit', 'debit'])],
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'currency' => ['required', Rule::in(config('platform.currencies'))],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $adjustment = $this->adjustments->request(
            $request->user(),
            Tenant::query()->findOrFail($data['tenant_id']),
            $data['direction'],
            Money::of((string) $data['amount'], $data['currency']),
            $data['reason'],
        );

        return (new AdjustmentResource($adjustment->load(['tenant:id,name', 'requester'])))->response()->setStatusCode(201);
    }

    public function approveAdjustment(Request $request, FinancialAdjustment $adjustment): AdjustmentResource
    {
        $this->authorize('platform.adjustments.approve');
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);

        return new AdjustmentResource($this->adjustments->approve($adjustment, $request->user(), $data['notes'] ?? null)->load(['tenant:id,name', 'requester', 'reviewer']));
    }

    public function rejectAdjustment(Request $request, FinancialAdjustment $adjustment): AdjustmentResource
    {
        $this->authorize('platform.adjustments.approve');
        $data = $request->validate(['notes' => ['required', 'string', 'max:1000']]);

        return new AdjustmentResource($this->adjustments->reject($adjustment, $request->user(), $data['notes'])->load(['tenant:id,name', 'requester', 'reviewer']));
    }

    public function invoices(Request $request): AnonymousResourceCollection
    {
        $this->authorize('platform.finance.view');

        return InvoiceResource::collection(Invoice::query()
            ->when($request->query('tenant_id'), fn ($q, $id) => $q->where('tenant_id', $id))
            ->with('booking:id,reference')
            ->latest('id')->paginate(25));
    }
}
