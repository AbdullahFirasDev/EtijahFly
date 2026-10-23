<?php

namespace App\Http\Controllers\Api\V1\Partner;

use App\Domain\Finance\AccountService;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\LedgerAccount;
use App\Domain\Finance\StatementService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\PaymentService;
use App\Domain\Reports\ReportService;
use App\Domain\Shared\Storage\FileStorage;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Ticketing\DocumentService;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\LedgerAccountResource;
use App\Http\Resources\PaymentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceController extends Controller
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly StatementService $statements,
        private readonly PaymentService $payments,
        private readonly ReportService $reports,
        private readonly DocumentService $documents,
        private readonly FileStorage $storage,
    ) {}

    /** Company account(s): balance, credit limit, available funds. */
    public function account(Request $request): JsonResponse
    {
        $this->authorize('finance.view');
        $tenant = Tenant::query()->findOrFail($request->user()->tenant_id);
        $this->accounts->walletFor($tenant); // ensure the default wallet exists

        return response()->json([
            'default_currency' => $tenant->default_currency,
            'accounts' => LedgerAccountResource::collection(
                LedgerAccount::query()->where('code', LedgerAccount::AGENCY_WALLET)->orderBy('currency')->get()
            ),
        ]);
    }

    /** Statement of account; `format=csv` downloads a spreadsheet-friendly file. */
    public function statement(Request $request): JsonResponse|StreamedResponse
    {
        $this->authorize('finance.view');
        $data = $request->validate([
            'currency' => ['nullable', Rule::in(config('platform.currencies'))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'format' => ['nullable', Rule::in(['json', 'csv'])],
        ]);

        $tenant = Tenant::query()->findOrFail($request->user()->tenant_id);
        $account = $this->accounts->walletFor($tenant, $data['currency'] ?? null);
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : now()->startOfMonth();
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay();

        $statement = $this->statements->forAccount($account, $from, $to);

        if (($data['format'] ?? 'json') === 'csv') {
            return response()->streamDownload(function () use ($statement) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
                fputcsv($out, ['date', 'type', 'description', 'debit', 'credit', 'balance']);
                fputcsv($out, [$statement['from']->toDateString(), 'opening', '', '', '', $statement['opening']->toDecimal()]);
                foreach ($statement['entries'] as $row) {
                    fputcsv($out, [$row['date'], $row['type'], $row['description'], $row['debit'], $row['credit'], $row['balance']]);
                }
                fputcsv($out, [$statement['to']->toDateString(), 'closing', '', $statement['total_debits']->toDecimal(), $statement['total_credits']->toDecimal(), $statement['closing']->toDecimal()]);
                fclose($out);
            }, "statement-{$account->currency}-{$from->toDateString()}-{$to->toDateString()}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return response()->json([
            'account' => new LedgerAccountResource($account),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'opening_balance' => $statement['opening']->toDecimal(),
            'closing_balance' => $statement['closing']->toDecimal(),
            'total_debits' => $statement['total_debits']->toDecimal(),
            'total_credits' => $statement['total_credits']->toDecimal(),
            'entries' => $statement['entries'],
        ]);
    }

    public function payments(Request $request): AnonymousResourceCollection
    {
        $this->authorize('finance.view');

        return PaymentResource::collection(Payment::query()->with(['submitter', 'reviewer'])->latest('id')->paginate(20));
    }

    /**
     * Report a bank transfer / cash deposit with optional proof. Credited after platform
     * approval. Requires an Idempotency-Key header.
     */
    public function storePayment(Request $request): JsonResponse
    {
        $this->authorize('payments.submit');
        $data = $request->validate([
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:999999999'],
            'currency' => ['required', Rule::in(config('platform.currencies'))],
            'method' => ['required', Rule::in(Payment::METHODS)],
            'paid_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'external_reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'proof' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        $payment = $this->payments->submit($request->user(), $data, $request->file('proof'), $request->attributes->get('idempotency_key'));

        return (new PaymentResource($payment->load(['submitter', 'reviewer'])))->response()->setStatusCode(201);
    }

    public function invoices(Request $request): AnonymousResourceCollection
    {
        $this->authorize('invoices.view');

        return InvoiceResource::collection(Invoice::query()->with('booking:id,reference')->latest('id')->paginate(25));
    }

    public function invoiceFile(Invoice $invoice): Response
    {
        $this->authorize('invoices.view');
        if (! $invoice->pdf_path) {
            $this->documents->generateInvoice($invoice);
            $invoice->refresh();
        }

        return response($this->storage->get(config('filestorage.buckets.documents'), $invoice->pdf_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$invoice->number.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function commissions(Request $request): JsonResponse
    {
        $this->authorize('commissions.view');
        [$from, $to] = $this->period($request);

        return response()->json(['data' => $this->reports->commissions($request->user()->tenant_id, $from, $to)]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function period(Request $request): array
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return [
            isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : now()->subDays(30)->startOfDay(),
            isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay(),
        ];
    }
}
