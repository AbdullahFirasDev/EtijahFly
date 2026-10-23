<?php

namespace App\Domain\Payments;

use App\Domain\Payments\Models\Payment;

/** Offline payments (bank transfer, cash, cheque): confirmed by staff review, not by webhook. */
class ManualTransferGateway implements PaymentGatewayInterface
{
    public function code(): string
    {
        return 'manual';
    }

    public function initiate(Payment $payment): array
    {
        return ['redirect_url' => null, 'external_reference' => $payment->external_reference];
    }

    public function verifyWebhook(string $payload, array $headers): ?string
    {
        return null;
    }
}
