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
 * PayPal Orders v2 (approve on PayPal, then Nawy captures).
 *
 * PayPal has no 3-decimal currencies and does not settle AED/OMR/SAR; the
 * config's currency list decides where it shows (seeded disabled until a
 * USD conversion exists). Amounts are decimal strings.
 *
 * API assumptions (to confirm with sandbox keys): `https://api-m.sandbox.paypal.com` / `https://api-m.paypal.com`;
 * OAuth `POST /v1/oauth2/token` (basic client_id:client_secret, client_credentials); `POST /v2/checkout/orders`
 * with `PayPal-Request-Id` (idempotency) → `id` + link `payer-action`/`approve`; `GET /v2/checkout/orders/{id}`
 * status CREATED | APPROVED | COMPLETED | VOIDED; `POST /v2/checkout/orders/{id}/capture`;
 * `POST /v2/payments/captures/{capture_id}/refund`. Webhooks are verified by PayPal itself:
 * `POST /v1/notifications/verify-webhook-signature` with the PAYPAL-* transmission headers and the
 * configured `webhook_id` → `verification_status: SUCCESS`. Event id = `WH-…`.
 */
class PayPalGateway extends HttpGateway
{
    public function createPayment(PaymentRequest $request): GatewaySession
    {
        $this->ensureSupported($request->amount);

        $body = $this->call(fn () => $this->client()->withHeaders(['PayPal-Request-Id' => $request->idempotencyKey])->post('/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $request->reference,
                'custom_id' => $request->reference,
                'invoice_id' => $request->orderNumber.'-'.substr($request->reference, 0, 8),
                'description' => mb_substr($request->description, 0, 127),
                'amount' => ['currency_code' => $request->amount->getCurrency()->getCurrencyCode(), 'value' => $this->decimal($request->amount)],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => [
                'return_url' => $request->returnUrl,
                'cancel_url' => $request->cancelUrl,
                'user_action' => 'PAY_NOW',
                'locale' => $request->locale === 'ar' ? 'ar-'.$request->countryIso2 : 'en-'.$request->countryIso2,
            ]]],
        ]));

        $link = collect($body['links'] ?? [])->first(fn (array $link): bool => in_array($link['rel'] ?? null, ['payer-action', 'approve'], true));

        return new GatewaySession((string) ($body['id'] ?? throw new GatewayError('PayPal did not return an order.')), $link['href'] ?? null);
    }

    public function verify(Payment $payment): GatewayOutcome
    {
        return $this->outcomeFromOrder($this->order($payment));
    }

    public function capture(Payment $payment, Money $amount): GatewayOutcome
    {
        $body = $this->call(fn () => $this->client()
            ->withHeaders(['PayPal-Request-Id' => $payment->reference.'-capture'])
            ->withBody('{}', 'application/json')
            ->post('/v2/checkout/orders/'.$payment->gateway_reference.'/capture'));

        return $this->outcomeFromOrder($body);
    }

    public function refund(Payment $payment, Money $amount, string $idempotencyKey, ?string $reason = null): GatewayRefund
    {
        $captureId = data_get($this->order($payment), 'purchase_units.0.payments.captures.0.id')
            ?? throw new GatewayError('PayPal order has no capture to refund.');

        $body = $this->call(fn () => $this->client()->withHeaders(['PayPal-Request-Id' => $idempotencyKey])->post('/v2/payments/captures/'.$captureId.'/refund', [
            'amount' => ['currency_code' => $amount->getCurrency()->getCurrencyCode(), 'value' => $this->decimal($amount)],
            'note_to_payer' => mb_substr((string) ($reason ?? 'Refund'), 0, 255),
        ]));

        return new GatewayRefund((string) $body['id'], ($body['status'] ?? null) === 'COMPLETED');
    }

    public function parseWebhook(Request $request): WebhookNotification
    {
        $headers = [
            'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
            'cert_url' => $request->header('PAYPAL-CERT-URL'),
            'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
            'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
            'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
        ];
        $payload = (array) $request->json()->all();

        if (in_array(null, $headers, true) || $payload === []) {
            throw new InvalidWebhookSignature('Missing PayPal transmission headers.');
        }

        try {
            $verification = $this->call(fn () => $this->client()->post('/v1/notifications/verify-webhook-signature', $headers + [
                'webhook_id' => $this->credential('webhook_id'),
                'webhook_event' => $payload,
            ]));
        } catch (GatewayError $error) {
            throw new InvalidWebhookSignature('PayPal could not verify the webhook.', previous: $error);
        }

        if (($verification['verification_status'] ?? null) !== 'SUCCESS') {
            throw new InvalidWebhookSignature('Invalid PayPal signature.');
        }

        $type = (string) ($payload['event_type'] ?? 'unknown');
        $resource = (array) ($payload['resource'] ?? []);
        $orderId = $type === 'CHECKOUT.ORDER.APPROVED' ? ($resource['id'] ?? null) : data_get($resource, 'supplementary_data.related_ids.order_id');

        $outcome = match ($type) {
            'CHECKOUT.ORDER.APPROVED' => new GatewayOutcome(PaymentStatus::Authorized, $orderId),
            'PAYMENT.CAPTURE.COMPLETED' => new GatewayOutcome(PaymentStatus::Captured, $orderId, data_get($resource, 'amount.value')),
            'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED' => new GatewayOutcome(PaymentStatus::Failed, $orderId, failureCode: 'capture_denied'),
            default => null,
        };

        return new WebhookNotification(
            (string) ($payload['id'] ?? throw new InvalidWebhookSignature('PayPal event without id.')),
            $type,
            $orderId,
            $resource['custom_id'] ?? data_get($resource, 'purchase_units.0.custom_id'),
            $outcome,
            $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function outcomeFromOrder(array $order): GatewayOutcome
    {
        $id = $order['id'] ?? null;

        return match ($order['status'] ?? null) {
            'COMPLETED' => new GatewayOutcome(PaymentStatus::Captured, $id, data_get($order, 'purchase_units.0.payments.captures.0.amount.value')),
            'APPROVED' => new GatewayOutcome(PaymentStatus::Authorized, $id),
            'VOIDED' => new GatewayOutcome(PaymentStatus::Failed, $id, failureCode: 'voided'),
            default => new GatewayOutcome(PaymentStatus::Pending, $id),
        };
    }

    private function ensureSupported(Money $amount): void
    {
        if ($amount->getCurrency()->getDefaultFractionDigits() > 2) {
            throw new GatewayError('PayPal does not support 3-decimal currencies.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function order(Payment $payment): array
    {
        return $this->call(fn () => $this->client()->get('/v2/checkout/orders/'.$payment->gateway_reference));
    }

    private function baseUrl(): string
    {
        return $this->isLive() ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    private function client(): PendingRequest
    {
        $token = $this->call(fn () => $this->http()
            ->baseUrl($this->baseUrl())
            ->withBasicAuth($this->credential('client_id'), $this->credential('client_secret'))
            ->asForm()
            ->post('/v1/oauth2/token', ['grant_type' => 'client_credentials']))['access_token'] ?? throw new GatewayError('PayPal did not return an access token.');

        return $this->http()->baseUrl($this->baseUrl())->withToken((string) $token)->asJson();
    }
}
