<?php

namespace App\Http\Controllers\Api\V1\Partner;

use App\Domain\Reports\ReportService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    /** Sales, commissions and booking status summary for the company. */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('reports.view');
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : now()->subDays(30)->startOfDay();
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay();

        return response()->json($this->reports->summary($request->user()->tenant_id, $from, $to, includeInternal: false));
    }
}
