<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Reports\ReportService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function dashboard(): JsonResponse
    {
        $this->authorize('platform.dashboard');

        return response()->json($this->reports->platformDashboard());
    }

    /** Sales, supplier cost, markup, commission and profit (by currency). */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('platform.reports.view');
        [$from, $to, $tenantId] = $this->filters($request);

        return response()->json($this->reports->summary($tenantId, $from, $to, includeInternal: true));
    }

    public function commissions(Request $request): JsonResponse
    {
        $this->authorize('platform.reports.view');
        [$from, $to, $tenantId] = $this->filters($request);

        return response()->json(['data' => $this->reports->commissions($tenantId, $from, $to)]);
    }

    /** Agency balances, credit usage and outstanding amounts. */
    public function receivables(): JsonResponse
    {
        $this->authorize('platform.finance.view');

        return response()->json(['data' => $this->reports->receivables()]);
    }

    /** @return array{0: Carbon, 1: Carbon, 2: int|null} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'tenant_id' => ['nullable', 'integer'],
        ]);

        return [
            isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : now()->subDays(30)->startOfDay(),
            isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay(),
            isset($data['tenant_id']) ? (int) $data['tenant_id'] : null,
        ];
    }
}
