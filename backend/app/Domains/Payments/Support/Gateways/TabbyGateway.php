<?php

namespace App\Domains\Payments\Support\Gateways;

use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Exceptions\GatewayError;
use App\Domains\Payments\Exceptions\InvalidWebhookSignature;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Support\Data\GatewayOutcome;
use App\Domains\Payments\Support\Data\GatewayRefund;
use App\Domains\Payments\Support\Data\GatewaySession;
use App\Domains\Payments\Support\Data\PaymentRequest;
use App\Domains\Payments\Support\Data\WebhookNotification;
use Brick\Money\Money;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;

/**
 * Tabby pay-in-4 (BNPL): the customer is authorised on Tabby's page, then
 * Nawy captures. Amounts are decimal strings.
 *
 * API assumptions (to confirm with sandbox keys): `https://api.tabby.ai` for both modes (test keys = sandbox);
 * bearer secret key; `POST /api/v2/checkout` with `merchant_code` → `status` created | rejected, `payment.id`,
 * `configuration.available_products.installments.0.web_url`; `GET /api/v2/payments/{id}` → status CREATED |
 * AUTHORIZED | CLOSED (captured) | REJECTED | EXPIRED; `POST /api/v2/payments/{id}/captures {amount, reference_id}`;
 * `POST /api/v2/payments/{id}/refunds {amount, reference_id, reason}` (reference_id = Nawy's refund key).
 * Webhooks are not HMAC-signed: Tabby sends the custom auth header registered with the webhook — Nawy registers
 * `X-Webhook-Secret: {webhook_secret}` and compares it in constant time. Body `{id, status, amount, order:{…}}`;
 * there is no event id, so the idempotency id is `{payment id}:{status}:{captures count}`.
 */
class TabbyGateway extends HttpGateway
{
    public const WEBHOOK_HEADER = 'X-Webhook-Secret';

    public function createPayment(PaymentRequest $request): GatewaySession
    {
        $body = $this->call(fn () => $this->client()->post('/api/v2/checkout', [
            'payment' => [
                'amount' => $this->decimal($request->amount),
                'currency' => $request->amount->getCurrency()->getCurrencyCode(),
                'description' => $request->description,
                'buyer' => ['email' => $request->customerEmail, 'name' => $request->customerName, 'phone' => (string) $request->customerPhone],
                'shipping_address' => [
                    'city' => (string) ($request->shippingAddress['city'] ?? ''),
                    'address' => trim(($request->shippingAddress['street'] ?? '').' '.($request->shippingAddress['area'] ?? '')),
                    'zip' => '00000',
                ],
                'order' => ['reference_id' => $request->orderNumber],
                'meta' => ['order_id' => $request->orderNumber, 'customer' => $request->reference],
            ],
            'lang' => $request->locale === 'ar' ? 'ar' : 'en',
            'merchant_code' => $this->credential('merchant_code'),
            'merchant_urls' => ['success' => $request->returnUrl, 'cancel' => $request->cancelUrl, 'failure' => $request->cancelUrl],
        ]));

        if (($body['status'] ?? null) === 'rejected') {
            throw new GatewayError('Tabby is not available for this order.');
        }

        return new GatewaySession(
            (string) (data_get($body, 'payment.id') ?? throw new GatewayError('Tabby did not return a payment.')),
            data_get($body, 'configuration.available_products.installments.0.web_url'),
        );
    }

    public function verify(Payment $payment): GatewayOutcome
    {
        return $this->outcome($this->call(fn () => $this->client()->get('/api/v2/payments/'.$payment->gateway_reference)));
    }

    public function capture(Payment $payment, Money $amount): GatewayOutcome
    {
        return $this->outcome($this->call(fn () => $this->client()->post('/api/v2/payments/'.$payment->gateway_reference.'/captures', [
            'amount' => $this->decimal($amount),
            'reference_id' => $payment->reference,
        ])));
    }

    public function refund(Payment $payment, Money $amount, string $idempotencyKey, ?string $reason = null): GatewayRefund
    {
        $body = $this->call(fn () => $this->client()->post('/api/v2/payments/'.$payment->gateway_reference.'/refunds', [
            'amount' => $this->decimal($amount),
            'reference_id' => $idempotencyKey,
            'reason' => (string) ($reason ?? 'Refund'),
        ]));

        $refund = collect($body['refunds'] ?? [])->firstWhere('reference_id', $idempotencyKey) ?? collect($body['refunds'] ?? [])->last();

        return new GatewayRefund((string) ($refund['id'] ?? $idempotencyKey));
    }

    public function parseWebhook(Request $request): WebhookNotification
    {
        $secret = (string) $request->header(self::WEBHOOK_HEADER);

        if ($secret === '' || ! hash_equals($this->credential('webhook_secret'), $secret)) {
            throw new InvalidWebhookSignature('Invalid Tabby webhook secret.');
        }

        $payload = (array) $request->json()->all();
        $paymentId = (string) ($payload['id'] ?? throw new InvalidWebhookSignature('Tabby webhook without payment id.'));
        $status = strtolower((string) ($payload['status'] ?? 'unknown'));

        return new WebhookNotification(
            $paymentId.':'.$status.':'.count($payload['captures'] ?? []),
            'payment.'.$status,
            $paymentId,
            data_get($payload, 'meta.customer'),
            $this->outcome($payload),
            $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private function outcome(array $payment): GatewayOutcome
    {
        $id = $payment['id'] ?? null;
        $amount = isset($payment['amount']) ? (string) $payment['amount'] : null;

        return match (strtoupper((string) ($payment['status'] ?? ''))) {
            'AUTHORIZED' => new GatewayOutcome(PaymentStatus::Authorized, $id, $amount),
            'CLOSED' => new GatewayOutcome(PaymentStatus::Captured, $id, $amount),
            'REJECTED' => new GatewayOutcome(PaymentStatus::Failed, $id, failureCode: 'rejected', failureMessage: __('Tabby declined the payment.')),
            'EXPIRED' => new GatewayOutcome(PaymentStatus::Expired, $id),
            default => new GatewayOutcome(PaymentStatus::Pending, $id),
        };
    }

    private function client(): PendingRequest
    {
        return $this->http()->baseUrl('https://api.tabby.ai')->withToken($this->credential('secret_key'));
    }
}
