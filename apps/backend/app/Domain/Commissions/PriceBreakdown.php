<?php

namespace App\Domain\Commissions;

use App\Domain\Shared\Money\Money;

/**
 * Price composition of an offer, all in the offer (supplier) currency:
 *
 *   sale_total      = supplier_total + markup          (what the agency is charged)
 *   agency_net      = sale_total - commission          (agency's effective cost)
 *   platform_profit = markup - commission
 */
final readonly class PriceBreakdown
{
    /**
     * @param  array<string, mixed>|null  $markupRule
     * @param  array<string, mixed>|null  $commissionRule
     */
    public function __construct(
        public Money $supplierBase,
        public Money $supplierTaxes,
        public Money $markup,
        public Money $commission,
        public ?array $markupRule,
        public ?array $commissionRule,
    ) {}

    public function currency(): string
    {
        return $this->supplierBase->currency;
    }

    public function supplierTotal(): Money
    {
        return $this->supplierBase->plus($this->supplierTaxes);
    }

    public function saleTotal(): Money
    {
        return $this->supplierTotal()->plus($this->markup);
    }

    public function agencyNet(): Money
    {
        return $this->saleTotal()->minus($this->commission);
    }

    public function platformProfit(): Money
    {
        return $this->markup->minus($this->commission);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency(),
            'supplier_base' => $this->supplierBase->toDecimal(),
            'supplier_taxes' => $this->supplierTaxes->toDecimal(),
            'supplier_total' => $this->supplierTotal()->toDecimal(),
            'markup' => $this->markup->toDecimal(),
            'sale_total' => $this->saleTotal()->toDecimal(),
            'commission' => $this->commission->toDecimal(),
            'agency_net' => $this->agencyNet()->toDecimal(),
            'platform_profit' => $this->platformProfit()->toDecimal(),
            'markup_rule' => $this->markupRule,
            'commission_rule' => $this->commissionRule,
        ];
    }

    /** Fields agencies may see (supplier cost and platform margin stay internal). */
    public function toAgencyArray(): array
    {
        return [
            'currency' => $this->currency(),
            'sale_total' => $this->saleTotal()->toDecimal(),
            'commission' => $this->commission->toDecimal(),
            'agency_net' => $this->agencyNet()->toDecimal(),
        ];
    }
}
