<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\AuditLog\Auditor;
use App\Domain\Commissions\Models\ExchangeRate;
use App\Domain\Commissions\Models\PricingRule;
use App\Domain\Commissions\PricingContext;
use App\Domain\Commissions\PricingEngine;
use App\Domain\FlightSearch\Enums\CabinClass;
use App\Domain\FlightSearch\Enums\TripType;
use App\Domain\Providers\Booking\Data\Fare;
use App\Http\Controllers\Controller;
use App\Http\Resources\PricingRuleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class PricingController extends Controller
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly PricingEngine $engine,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('platform.pricing.view');

        return PricingRuleResource::collection(PricingRule::query()
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->when($request->query('tenant_id'), fn ($q, $id) => $q->where('tenant_id', $id))
            ->with('tenant')
            ->orderBy('type')->orderByDesc('priority')->orderBy('id')
            ->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('platform.pricing.manage');
        $rule = PricingRule::query()->create($this->validated($request) + ['created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
        $this->auditor->record('pricing_rule.created', $rule, new: $rule->only(['type', 'calculation', 'value', 'currency', 'basis', 'tenant_id']));

        return (new PricingRuleResource($rule->load('tenant')))->response()->setStatusCode(201);
    }

    public function update(Request $request, PricingRule $rule): PricingRuleResource
    {
        $this->authorize('platform.pricing.manage');
        $rule->fill($this->validated($request) + ['updated_by' => $request->user()->id])->save();
        [$old, $new] = Auditor::diff($rule);
        $this->auditor->record('pricing_rule.updated', $rule, $old, $new);

        return new PricingRuleResource($rule->load('tenant'));
    }

    public function destroy(PricingRule $rule): JsonResponse
    {
        $this->authorize('platform.pricing.manage');
        $rule->delete();
        $this->auditor->record('pricing_rule.deleted', $rule, $rule->only(['type', 'name', 'value']));

        return response()->json(['message' => 'ok']);
    }

    /** Preview which rules apply and the resulting price for a hypothetical supplier fare. */
    public function preview(Request $request): JsonResponse
    {
        $this->authorize('platform.pricing.view');
        $data = $request->validate([
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'base' => ['required', 'decimal:0,2', 'min:0'],
            'taxes' => ['required', 'decimal:0,2', 'min:0'],
            'currency' => ['required', Rule::in(config('platform.currencies'))],
            'airline' => ['nullable', 'string', 'size:2'],
            'cabin' => ['nullable', Rule::enum(CabinClass::class)],
            'trip_type' => ['nullable', Rule::enum(TripType::class)],
            'origin' => ['nullable', 'string', 'size:3'],
            'destination' => ['nullable', 'string', 'size:3'],
            'provider_code' => ['nullable', 'string', 'max:40'],
        ]);

        $breakdown = $this->engine->price(Fare::of((string) $data['base'], (string) $data['taxes'], $data['currency']), new PricingContext(
            tenantId: $data['tenant_id'] ?? null,
            airlineCode: isset($data['airline']) ? strtoupper($data['airline']) : null,
            cabin: $data['cabin'] ?? 'economy',
            tripType: $data['trip_type'] ?? 'one_way',
            origin: strtoupper($data['origin'] ?? 'XXX'),
            destination: strtoupper($data['destination'] ?? 'XXX'),
            providerCode: $data['provider_code'] ?? 'mock',
        ));

        return response()->json(['data' => $breakdown->toArray()]);
    }

    public function exchangeRates(): JsonResponse
    {
        $this->authorize('platform.pricing.view');

        return response()->json(['data' => ExchangeRate::query()->with('creator:id,name')->orderByDesc('effective_at')->orderByDesc('id')->limit(100)->get()]);
    }

    /** Record a new exchange rate (history is append-only). */
    public function storeExchangeRate(Request $request): JsonResponse
    {
        $this->authorize('platform.exchange_rates.manage');
        $currencies = config('platform.currencies');
        $data = $request->validate([
            'base_currency' => ['required', Rule::in($currencies)],
            'quote_currency' => ['required', Rule::in($currencies), 'different:base_currency'],
            'rate' => ['required', 'decimal:0,6', 'gt:0'],
            'effective_at' => ['nullable', 'date'],
        ]);

        $rate = ExchangeRate::query()->create($data + ['effective_at' => $data['effective_at'] ?? now(), 'source' => 'manual', 'created_by' => $request->user()->id]);
        $this->auditor->record('exchange_rate.created', $rate, new: $data);

        return response()->json(['data' => $rate], 201);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in([PricingRule::TYPE_MARKUP, PricingRule::TYPE_COMMISSION])],
            'calculation' => ['required', Rule::in([PricingRule::CALC_PERCENT, PricingRule::CALC_FIXED])],
            'value' => ['required', 'decimal:0,4', 'min:0', 'max:100000'],
            'currency' => ['nullable', 'required_if:calculation,fixed', Rule::in(config('platform.currencies'))],
            'basis' => ['nullable', Rule::in([PricingRule::BASIS_SUPPLIER_TOTAL, PricingRule::BASIS_BASE_FARE])],
            'min_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'max_amount' => ['nullable', 'decimal:0,2', 'min:0', 'gte:min_amount'],
            'airline_codes' => ['nullable', 'array'],
            'airline_codes.*' => ['string', 'size:2'],
            'cabin' => ['nullable', Rule::enum(CabinClass::class)],
            'trip_type' => ['nullable', Rule::enum(TripType::class)],
            'origin' => ['nullable', 'string', 'size:3'],
            'destination' => ['nullable', 'string', 'size:3'],
            'provider_code' => ['nullable', 'string', 'max:40'],
            'priority' => ['nullable', 'integer', 'min:-1000', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after:valid_from'],
        ]);

        if ($data['calculation'] === PricingRule::CALC_PERCENT && (float) $data['value'] > 100) {
            abort(response()->json(['message' => __('validation.max.numeric', ['attribute' => 'value', 'max' => 100]), 'code' => 'VALIDATION_FAILED', 'errors' => ['value' => ['<= 100']]], 422));
        }

        $data['basis'] ??= PricingRule::BASIS_SUPPLIER_TOTAL;
        $data['priority'] ??= 0;
        $data['is_active'] ??= true;
        $data['airline_codes'] = isset($data['airline_codes']) ? array_map('strtoupper', $data['airline_codes']) : null;
        foreach (['origin', 'destination'] as $k) {
            $data[$k] = isset($data[$k]) ? strtoupper($data[$k]) : null;
        }

        return $data;
    }
}
