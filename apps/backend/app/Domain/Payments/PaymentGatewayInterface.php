<?php

namespace App\Domain\Payments;

use App\Domain\Payments\Models\Payment;

/**
 * Extension point for online payment gateways (card, local wallets). No online gateway is
 * connected yet: agencies fund their accounts by bank transfer/cash, recorded as `manual`
 * payments that platform finance staff approve. A gateway implementation would create the
 * payment session and confirm it from a verified webhook - never from the browser redirect.
 */
interface PaymentGatewayInterface
{
    public function code(): string;

    /** @return array{redirect_url: string|null, external_reference: string|null} */
    public function initiate(Payment $payment): array;

    /** Verify a webhook payload/signature and return the external reference it confirms. */
    public function verifyWebhook(string $payload, array $headers): ?string;
}
