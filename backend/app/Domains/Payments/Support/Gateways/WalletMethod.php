<?php

namespace App\Domains\Payments\Support\Gateways;

use App\Domains\Finance\Exceptions\InsufficientFunds;
use App\Domains\Finance\Support\Wallets;
use App\Domains\Payments\Enums\PaymentStatus;
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
 * Nawy Credits (internal method, WP4). Starting the payment takes the whole
 * amount from the customer wallet (promotional credits first, then
 * spendable) into `wallet_clearing`, so verify reports it captured at once.
 * The split is kept in `client_data.wallet` for refunds. A refund is
 * booked back to the wallet by the refund service (ledger), so the
 * provider call itself only returns a reference.
 */
class WalletMethod implements PaymentGateway
{
    public function __construct(private Wallets $wallets) {}

    public function createPayment(PaymentRequest $request): GatewaySession
    {
        $payment = Payment::query()->where('reference', $request->reference)->firstOrFail();

        try {
            $split = $this->wallets->pay($payment->customer_user_id, $request->amount, "payment:{$payment->reference}:wallet", $payment);
        } catch (InsufficientFunds $insufficient) {
            throw new PaymentMethodUnavailable($insufficient->getMessage(), previous: $insufficient);
        }

        return new GatewaySession('wallet_'.$payment->reference, null, ['wallet' => [
            'promotional' => (string) $split['promotional']->getAmount(),
            'spendable' => (string) $split['spendable']->getAmount(),
        ]]);
    }

    public function verify(Payment $payment): GatewayOutcome
    {
        if (isset($payment->client_data['wallet'])) {
            return new GatewayOutcome(PaymentStatus::Captured, $payment->gateway_reference, (string) $payment->amount);
        }

        return new GatewayOutcome($payment->status);
    }

    public function capture(Payment $payment, Money $amount): GatewayOutcome
    {
        return new GatewayOutcome(PaymentStatus::Captured, $payment->gateway_reference, (string) $amount->getAmount());
    }

    public function refund(Payment $payment, Money $amount, string $idempotencyKey, ?string $reason = null): GatewayRefund
    {
        return new GatewayRefund('wallet_'.$idempotencyKey);
    }

    public function parseWebhook(Request $request): WebhookNotification
    {
        throw new InvalidWebhookSignature('Nawy Credits have no webhooks.');
    }
}
