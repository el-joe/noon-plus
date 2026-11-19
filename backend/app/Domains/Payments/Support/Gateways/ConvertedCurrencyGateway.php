<?php

namespace App\Domains\Payments\Support\Gateways;

use App\Domains\Payments\Exceptions\GatewayError;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Models\PaymentRefund;
use App\Domains\Payments\Models\SavedPaymentCard;
use App\Domains\Payments\Support\Data\GatewayOutcome;
use App\Domains\Payments\Support\Data\GatewayRefund;
use App\Domains\Payments\Support\Data\GatewaySession;
use App\Domains\Payments\Support\Data\PaymentRequest;
use App\Domains\Payments\Support\Data\WebhookNotification;
use App\Domains\Payments\Support\PaymentAttempts;
use App\Domains\Payments\Support\PaymentCurrencyConversion;
use Brick\Money\Money;
use Illuminate\Http\Request;

/**
 * Wraps the adapter of a payment charged in another currency (PayPal USD):
 * the adapter only ever sees the charge currency and amount (a copy of the
 * payment that is never saved), refunds are sent in the charge currency
 * worked out with the stored rate. Amounts the provider reports are turned
 * back into the country currency by {@see PaymentAttempts::applyOutcome()}.
 */
class ConvertedCurrencyGateway implements ChargesSavedCards, PaymentGateway
{
    public function __construct(private PaymentGateway $inner, private PaymentCurrencyConversion $conversion) {}

    public function createPayment(PaymentRequest $request): GatewaySession
    {
        return $this->inner->createPayment($request);
    }

    public function verify(Payment $payment): GatewayOutcome
    {
        return $this->inner->verify($this->chargeView($payment));
    }

    public function capture(Payment $payment, Money $amount): GatewayOutcome
    {
        return $this->inner->capture($this->chargeView($payment), $this->conversion->gatewayAmount($payment));
    }

    public function refund(Payment $payment, Money $amount, string $idempotencyKey, ?string $reason = null): GatewayRefund
    {
        $stored = PaymentRefund::query()->where('payment_id', $payment->id)->where('idempotency_key', $idempotencyKey)->value('charge_amount');
        $charge = $stored !== null
            ? Money::of((string) $stored, (string) $payment->charge_currency)
            : $this->conversion->refundCharge($payment, $amount);

        return $this->inner->refund($this->chargeView($payment), $charge, $idempotencyKey, $reason);
    }

    /**
     * The request already carries the charge amount (converted by {@see PaymentAttempts}).
     */
    public function chargeSavedCard(PaymentRequest $request, SavedPaymentCard $card): GatewayOutcome
    {
        if (! $this->inner instanceof ChargesSavedCards) {
            throw new GatewayError('This gateway cannot charge saved cards.');
        }

        return $this->inner->chargeSavedCard($request, $card);
    }

    public function parseWebhook(Request $request): WebhookNotification
    {
        return $this->inner->parseWebhook($request);
    }

    /**
     * The payment as the provider knows it (charge currency and amount).
     */
    private function chargeView(Payment $payment): Payment
    {
        $view = clone $payment;
        $view->setAttribute('currency', $payment->charge_currency);
        $view->setAttribute('amount', $payment->charge_amount);

        return $view;
    }
}
