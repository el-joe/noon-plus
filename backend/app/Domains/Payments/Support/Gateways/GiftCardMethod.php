<?php

namespace App\Domains\Payments\Support\Gateways;

use App\Domains\Payments\Exceptions\InvalidWebhookSignature;
use App\Domains\Payments\Exceptions\PaymentMethodUnavailable;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Support\Data\GatewayOutcome;
use App\Domains\Payments\Support\Data\GatewayRefund;
use App\Domains\Payments\Support\Data\GatewaySession;
use App\Domains\Payments\Support\Data\PaymentRequest;
use App\Domains\Payments\Support\Data\WebhookNotification;
use Brick\Money\Money;
use Illuminate\Http\Request;

/**
 * Gift card (internal method). Gift cards are redeemed into Nawy Credits
 * (WP4) and then paid with the wallet method, so this method is never
 * offered at checkout and refuses every call.
 */
class GiftCardMethod implements PaymentGateway
{
    public function createPayment(PaymentRequest $request): GatewaySession
    {
        throw $this->unavailable();
    }

    public function verify(Payment $payment): GatewayOutcome
    {
        throw $this->unavailable();
    }

    public function capture(Payment $payment, Money $amount): GatewayOutcome
    {
        throw $this->unavailable();
    }

    public function refund(Payment $payment, Money $amount, string $idempotencyKey, ?string $reason = null): GatewayRefund
    {
        throw $this->unavailable();
    }

    public function parseWebhook(Request $request): WebhookNotification
    {
        throw new InvalidWebhookSignature('Gift card has no webhooks.');
    }

    private function unavailable(): PaymentMethodUnavailable
    {
        return new PaymentMethodUnavailable(__('Redeem your gift card to Nawy Credits, then pay with Nawy Credits.'));
    }
}
