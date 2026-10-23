<?php

use App\Domain\Commissions\Models\ExchangeRate;
use App\Domain\Commissions\Models\PricingRule;
use App\Domain\Commissions\PricingContext;
use App\Domain\Commissions\PricingEngine;
use App\Domain\Providers\Booking\Data\Fare;

function ctx(?int $tenantId = null, string $airline = 'FZ', array $overrides = []): PricingContext
{
    return new PricingContext(...array_merge([
        'tenantId' => $tenantId, 'airlineCode' => $airline, 'cabin' => 'economy', 'tripType' => 'one_way',
        'origin' => 'BGW', 'destination' => 'DXB', 'providerCode' => 'mock',
    ], $overrides));
}

function rule(array $attributes): PricingRule
{
    return PricingRule::query()->create(array_merge(['name' => 'r', 'basis' => 'supplier_total', 'priority' => 0, 'is_active' => true], $attributes));
}

it('applies markup on the supplier total and commission on the base fare without double counting', function () {
    rule(['type' => 'markup', 'calculation' => 'percent', 'value' => '5']);
    rule(['type' => 'commission', 'calculation' => 'percent', 'value' => '2', 'basis' => 'base_fare']);

    $price = app(PricingEngine::class)->price(Fare::of('200.00', '61.00', 'USD'), ctx());

    expect($price->supplierTotal()->toDecimal())->toBe('261.00')
        ->and($price->markup->toDecimal())->toBe('13.05')        // 5% of 261.00
        ->and($price->saleTotal()->toDecimal())->toBe('274.05')
        ->and($price->commission->toDecimal())->toBe('4.00')     // 2% of 200.00 base, not of 274.05
        ->and($price->agencyNet()->toDecimal())->toBe('270.05')
        ->and($price->platformProfit()->toDecimal())->toBe('9.05');
});

it('applies fixed amounts with currency conversion and min/max bounds', function () {
    ExchangeRate::query()->create(['base_currency' => 'USD', 'quote_currency' => 'IQD', 'rate' => '1310', 'effective_at' => now()->subDay()]);
    rule(['type' => 'markup', 'calculation' => 'fixed', 'value' => '15', 'currency' => 'USD']);
    rule(['type' => 'commission', 'calculation' => 'percent', 'value' => '10', 'max_amount' => '5000', 'currency' => 'IQD']);

    $price = app(PricingEngine::class)->price(Fare::of('100000', '20000', 'IQD'), ctx());

    expect($price->markup->toDecimal())->toBe('19650.00')     // 15 USD at 1310
        ->and($price->commission->toDecimal())->toBe('5000.00'); // 10% = 12000 capped at 5000
});

it('picks exactly one rule per type: tenant-specific beats global, then priority', function () {
    $tenant = agency();
    rule(['type' => 'markup', 'calculation' => 'percent', 'value' => '10', 'priority' => 100]);
    rule(['type' => 'markup', 'calculation' => 'percent', 'value' => '3', 'tenant_id' => $tenant->id]);
    rule(['type' => 'markup', 'calculation' => 'percent', 'value' => '7', 'priority' => 50]);

    $engine = app(PricingEngine::class);
    $fare = Fare::of('100', '0', 'USD');

    expect($engine->price($fare, ctx($tenant->id))->markup->toDecimal())->toBe('3.00')
        ->and($engine->price($fare, ctx(null))->markup->toDecimal())->toBe('10.00');
});

it('matches rule filters (airline, route, cabin) and validity windows', function () {
    rule(['type' => 'markup', 'calculation' => 'percent', 'value' => '1']);
    rule(['type' => 'markup', 'calculation' => 'percent', 'value' => '8', 'airline_codes' => ['EK']]);
    rule(['type' => 'markup', 'calculation' => 'percent', 'value' => '9', 'destination' => 'IST']);
    rule(['type' => 'markup', 'calculation' => 'percent', 'value' => '50', 'valid_to' => now()->subDay()]);
    rule(['type' => 'markup', 'calculation' => 'percent', 'value' => '60', 'is_active' => false]);

    $engine = app(PricingEngine::class);
    $fare = Fare::of('100', '0', 'USD');

    expect($engine->price($fare, ctx(airline: 'EK'))->markup->toDecimal())->toBe('8.00')
        ->and($engine->price($fare, ctx(overrides: ['destination' => 'IST']))->markup->toDecimal())->toBe('9.00')
        ->and($engine->price($fare, ctx())->markup->toDecimal())->toBe('1.00');
});

it('caps commission at the markup so the platform never sells below cost', function () {
    rule(['type' => 'markup', 'calculation' => 'percent', 'value' => '1']);
    rule(['type' => 'commission', 'calculation' => 'percent', 'value' => '5']);

    $price = app(PricingEngine::class)->price(Fare::of('100', '0', 'USD'), ctx());

    expect($price->commission->toDecimal())->toBe('1.00')
        ->and($price->agencyNet()->toDecimal())->toBe('100.00')
        ->and($price->platformProfit()->isNegative())->toBeFalse();
});

it('previews pricing through the admin API and validates percent rules', function () {
    actingAsAdmin(platformUser());
    $this->postJson('/api/v1/admin/pricing-rules', ['name' => 'Bad', 'type' => 'markup', 'calculation' => 'percent', 'value' => '150'])->assertStatus(422);
    $this->postJson('/api/v1/admin/pricing-rules', ['name' => 'Markup', 'type' => 'markup', 'calculation' => 'percent', 'value' => '4'])->assertCreated();

    $this->postJson('/api/v1/admin/pricing-rules/preview', ['base' => '100', 'taxes' => '0', 'currency' => 'USD'])->assertOk()
        ->assertJsonPath('data.markup', '4.00')
        ->assertJsonPath('data.sale_total', '104.00');
});
