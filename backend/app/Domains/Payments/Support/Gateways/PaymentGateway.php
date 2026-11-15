<?php

namespace App\Domains\Payments\Support\Gateways;

use App\Domains\Payments\Exceptions\GatewayError;
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
 * Strategy per payment method (ADR 6, FR-CHK-3). Adapters take and return
 * money in major units; any provider-specific unit (Thawani baisa, Stripe
 * minor units) is converted inside the adapter only. A payment is never
 * trusted from the customer's redirect: it is confirmed by `verify` (server
 * call) or by a signature-checked webhook.
 */
interface PaymentGateway
{
    /**
     * Start a payment: returns the provider id and the redirect / client data.
     *
     * @throws GatewayError|PaymentMethodUnavailable
     */
    public function createPayment(PaymentRequest $request): GatewaySession;

    /**
     * Ask the provider for the current state of the payment.
     *
     * @throws GatewayError
     */
    public function verify(Payment $payment): GatewayOutcome;

    /**
     * Capture an authorised payment (BNPL); card gateways capture at once
     * and simply report captured.
     *
     * @throws GatewayError
     */
    public function capture(Payment $payment, Money $amount): GatewayOutcome;

    /**
     * Refund all or part of a captured payment; the idempotency key is sent
     * to the provider when it supports one.
     *
     * @throws GatewayError
     */
    public function refund(Payment $payment, Money $amount, string $idempotencyKey, ?string $reason = null): GatewayRefund;

    /**
     * Verify the signature of a provider notification and read it.
     *
     * @throws InvalidWebhookSignature
     */
    public function parseWebhook(Request $request): WebhookNotification;
}
