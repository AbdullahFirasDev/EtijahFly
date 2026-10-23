<?php

namespace App\Domain\Commissions;

use App\Domain\Commissions\Models\PricingRule;
use App\Domain\Providers\Booking\Data\Fare;
use App\Domain\Shared\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Turns a supplier fare into a sale price.
 *
 * Rules that prevent double counting:
 *  1. Exactly one markup rule and one commission rule apply per offer (the most specific
 *     matching rule, then highest priority, then oldest). Rules never stack.
 *  2. Markup is computed on the supplier price; commission is computed on the supplier
 *     base fare or supplier total (rule `basis`) - never on the marked-up price, and markup
 *     is never computed on commission.
 *  3. Commission is capped at the markup, so the agency's net cost never drops below the
 *     supplier price (platform profit >= 0).
 */
class PricingEngine
{
    public function __construct(private readonly CurrencyConverter $converter) {}

    public function price(Fare $fare, PricingContext $context, ?Carbon $at = null): PriceBreakdown
    {
        $at ??= now();
        $currency = $fare->currency();

        $markupRule = $this->selectRule(PricingRule::TYPE_MARKUP, $context, $at);
        $commissionRule = $this->selectRule(PricingRule::TYPE_COMMISSION, $context, $at);

        $markup = $markupRule ? $this->amountFor($markupRule, $fare) : Money::zero($currency);
        $commission = $commissionRule ? $this->amountFor($commissionRule, $fare) : Money::zero($currency);

        // Agency net (supplier + markup - commission) must stay >= supplier total,
        // i.e. the platform never sells below cost.
        $commission = $commission->min($markup);

        return new PriceBreakdown(
            supplierBase: $fare->base,
            supplierTaxes: $fare->taxes,
            markup: $markup,
            commission: $commission,
            markupRule: $markupRule?->summary(),
            commissionRule: $commissionRule?->summary(),
        );
    }

    public function selectRule(string $type, PricingContext $context, Carbon $at): ?PricingRule
    {
        /** @var Collection<int, PricingRule> $candidates */
        $candidates = PricingRule::query()->effective($type, $context->tenantId, $at)->get();

        return $candidates
            ->filter(fn (PricingRule $rule) => $rule->matches($context))
            ->sort(fn (PricingRule $a, PricingRule $b) => [$b->specificity(), $b->priority, $a->id] <=> [$a->specificity(), $a->priority, $b->id])
            ->first();
    }

    private function amountFor(PricingRule $rule, Fare $fare): Money
    {
        $currency = $fare->currency();
        $basis = $rule->basis === PricingRule::BASIS_BASE_FARE ? $fare->base : $fare->total();

        $amount = $rule->calculation === PricingRule::CALC_PERCENT
            ? $basis->percent((string) $rule->value)
            : $this->converter->convert(Money::of((string) $rule->value, $rule->currency ?? $currency), $currency);

        $boundsCurrency = $rule->currency ?? $currency;
        if ($rule->min_amount !== null) {
            $amount = $amount->max($this->converter->convert(Money::of((string) $rule->min_amount, $boundsCurrency), $currency));
        }
        if ($rule->max_amount !== null) {
            $amount = $amount->min($this->converter->convert(Money::of((string) $rule->max_amount, $boundsCurrency), $currency));
        }

        return $amount->max(Money::zero($currency));
    }
}
