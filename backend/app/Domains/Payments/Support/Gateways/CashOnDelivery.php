<?php

namespace App\Domains\Payments\Support\Gateways;

use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Exceptions\InvalidWebhookSignature;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Support\Data\GatewayOutcome;
use App\Domains\Payments\Support\Data\GatewayRefund;
use App\Domains\Payments\Support\Data\GatewaySession;
use App\Domains\Payments\Support\Data\PaymentRequest;
use App\Domains\Payments\Support\Data\WebhookNotification;
use Brick\Money\Money;
use Illuminate\Http\Request;

/**
 * Cash on delivery (internal method). Nothing to redirect to: the order is
 * confirmed straight away and the payment stays pending until the agent
 * collects the cash (ADR 15). Each delivered sub-order records a COD
 * collection; the payment is captured once nothing is left to deliver.
 * A COD refund goes to the customer wallet (SRS §7.5, `refund.cod_to_wallet`):
 * the refund service books it in the ledger, the provider call only
 * returns a reference.
 */
class CashOnDelivery implements PaymentGateway
{
    public function createPayment(PaymentRequest $request): GatewaySession
    {
        return new GatewaySession(null);
    }

    public function verify(Payment $payment): GatewayOutcome
    {
        return new GatewayOutcome($payment->status);
    }

    public function capture(Payment $payment, Money $amount): GatewayOutcome
    {
        return new GatewayOutcome(PaymentStatus::Captured, null, (string) $amount->getAmount());
    }

    public function refund(Payment $payment, Money $amount, string $idempotencyKey, ?string $reason = null): GatewayRefund
    {
        return new GatewayRefund('wallet_'.$idempotencyKey);
    }

    public function parseWebhook(Request $request): WebhookNotification
    {
        throw new InvalidWebhookSignature('Cash on delivery has no webhooks.');
    }
}
