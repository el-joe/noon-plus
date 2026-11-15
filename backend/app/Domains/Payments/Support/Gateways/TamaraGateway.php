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
 * Tamara split-in-3 (BNPL): checkout → customer approves → Nawy authorises
 * and captures. Money objects are `{amount, currency}` with `amount` a JSON
 * number, written as an exact literal.
 *
 * API assumptions (to confirm with sandbox keys): `https://api-sandbox.tamara.co` / `https://api.tamara.co`,
 * bearer API token; `POST /checkout` → `order_id`, `checkout_url`; `GET /orders/{order_id}` → status new |
 * approved | authorised | partially_captured | fully_captured | declined | expired | canceled;
 * `POST /orders/{order_id}/authorise`; `POST /payments/capture {order_id, total_amount, shipping_info}`;
 * `POST /payments/simplified-refund/{order_id} {total_amount, comment}`. Notifications carry a JWT
 * (`tamaraToken` query parameter or `Authorization: Bearer …`) signed HS256 with the notification token; it is
 * verified here without a package (signature + `exp`). Body `{order_id, order_reference_id, event_type}`;
 * the idempotency id is `{order_id}:{event_type}`.
 */
class TamaraGateway extends HttpGateway
{
    public function createPayment(PaymentRequest $request): GatewaySession
    {
        $currency = $request->amount->getCurrency()->getCurrencyCode();
        $total = ['amount' => '__TOTAL__', 'currency' => $currency];
        $zero = ['amount' => '__ZERO__', 'currency' => $currency];
        [$firstName, $lastName] = array_pad(explode(' ', trim($request->customerName), 2), 2, '-');
        $address = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'line1' => (string) ($request->shippingAddress['street'] ?? '-'),
            'city' => (string) ($request->shippingAddress['city'] ?? '-'),
            'country_code' => $request->countryIso2,
            'phone_number' => (string) $request->customerPhone,
        ];

        $body = $this->call(fn () => $this->client()->withBody($this->jsonWithNumbers([
            'order_reference_id' => $request->reference,
            'order_number' => $request->orderNumber,
            'total_amount' => $total,
            'description' => $request->description,
            'country_code' => $request->countryIso2,
            'payment_type' => 'PAY_BY_INSTALMENTS',
            'locale' => $request->locale === 'ar' ? 'ar_SA' : 'en_US',
            'items' => [[
                'reference_id' => $request->orderNumber,
                'type' => 'Physical',
                'name' => $request->description,
                'sku' => $request->orderNumber,
                'quantity' => 1,
                'total_amount' => $total,
            ]],
            'consumer' => ['first_name' => $firstName, 'last_name' => $lastName, 'email' => $request->customerEmail, 'phone_number' => (string) $request->customerPhone],
            'shipping_address' => $address,
            'tax_amount' => $zero,
            'shipping_amount' => $zero,
            'merchant_url' => [
                'success' => $request->returnUrl,
                'failure' => $request->cancelUrl,
                'cancel' => $request->cancelUrl,
                'notification' => $request->notifyUrl,
            ],
        ], ['__TOTAL__' => $this->decimal($request->amount), '__ZERO__' => '0']), 'application/json')->post('/checkout'));

        return new GatewaySession(
            (string) ($body['order_id'] ?? throw new GatewayError('Tamara did not return an order.')),
            $body['checkout_url'] ?? null,
        );
    }

    public function verify(Payment $payment): GatewayOutcome
    {
        return $this->outcome($this->call(fn () => $this->client()->get('/orders/'.$payment->gateway_reference)));
    }

    public function capture(Payment $payment, Money $amount): GatewayOutcome
    {
        $order = $this->call(fn () => $this->client()->get('/orders/'.$payment->gateway_reference));

        if (($order['status'] ?? null) === 'approved') {
            $this->call(fn () => $this->client()->withBody('{}', 'application/json')->post('/orders/'.$payment->gateway_reference.'/authorise'));
        }

        $body = $this->call(fn () => $this->client()->withBody($this->jsonWithNumbers([
            'order_id' => $payment->gateway_reference,
            'total_amount' => ['amount' => '__TOTAL__', 'currency' => $payment->currency],
            'shipping_info' => ['shipped_at' => now()->toIso8601String(), 'shipping_company' => 'Nawy'],
        ], ['__TOTAL__' => $this->decimal($amount)]), 'application/json')->post('/payments/capture'));

        return in_array($body['status'] ?? null, ['fully_captured', 'partially_captured'], true)
            ? new GatewayOutcome(PaymentStatus::Captured, $payment->gateway_reference, $this->decimal($amount))
            : $this->verify($payment);
    }

    public function refund(Payment $payment, Money $amount, string $idempotencyKey, ?string $reason = null): GatewayRefund
    {
        $body = $this->call(fn () => $this->client()->withBody($this->jsonWithNumbers([
            'total_amount' => ['amount' => '__TOTAL__', 'currency' => $amount->getCurrency()->getCurrencyCode()],
            'comment' => (string) ($reason ?? 'Refund').' ['.$idempotencyKey.']',
        ], ['__TOTAL__' => $this->decimal($amount)]), 'application/json')->post('/payments/simplified-refund/'.$payment->gateway_reference));

        return new GatewayRefund((string) ($body['refund_id'] ?? $idempotencyKey));
    }

    public function parseWebhook(Request $request): WebhookNotification
    {
        $token = (string) ($request->query('tamaraToken') ?: $request->bearerToken());

        if (! self::validJwt($token, $this->credential('notification_token'))) {
            throw new InvalidWebhookSignature('Invalid Tamara notification token.');
        }

        $payload = (array) $request->json()->all();
        $orderId = (string) ($payload['order_id'] ?? throw new InvalidWebhookSignature('Tamara notification without order id.'));
        $type = (string) ($payload['event_type'] ?? 'unknown');

        $outcome = match ($type) {
            'order_approved', 'order_authorised' => new GatewayOutcome(PaymentStatus::Authorized, $orderId),
            'order_captured' => new GatewayOutcome(PaymentStatus::Captured, $orderId),
            'order_declined', 'order_canceled' => new GatewayOutcome(PaymentStatus::Failed, $orderId, failureCode: $type),
            'order_expired' => new GatewayOutcome(PaymentStatus::Expired, $orderId),
            default => null,
        };

        return new WebhookNotification($orderId.':'.$type, $type, $orderId, $payload['order_reference_id'] ?? null, $outcome, $payload);
    }

    /**
     * HS256 JWT check: signature with the shared token and, when present, not expired.
     */
    public static function validJwt(string $jwt, string $secret): bool
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            return false;
        }

        [$header, $claims, $signature] = $parts;
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $header.'.'.$claims, $secret, true)), '+/', '-_'), '=');
        $decodedHeader = json_decode((string) base64_decode(strtr($header, '-_', '+/')), true);
        $decodedClaims = json_decode((string) base64_decode(strtr($claims, '-_', '+/')), true);

        return hash_equals($expected, $signature)
            && is_array($decodedHeader) && ($decodedHeader['alg'] ?? null) === 'HS256'
            && is_array($decodedClaims) && (! isset($decodedClaims['exp']) || (int) $decodedClaims['exp'] >= now()->getTimestamp());
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function outcome(array $order): GatewayOutcome
    {
        $id = $order['order_id'] ?? null;
        $amount = data_get($order, 'total_amount.amount');
        $amount = $amount === null ? null : (string) $amount;

        return match ($order['status'] ?? null) {
            'approved', 'authorised' => new GatewayOutcome(PaymentStatus::Authorized, $id, $amount),
            'partially_captured', 'fully_captured', 'partially_refunded', 'fully_refunded' => new GatewayOutcome(PaymentStatus::Captured, $id, $amount),
            'declined', 'canceled' => new GatewayOutcome(PaymentStatus::Failed, $id, failureCode: (string) $order['status']),
            'expired' => new GatewayOutcome(PaymentStatus::Expired, $id),
            default => new GatewayOutcome(PaymentStatus::Pending, $id),
        };
    }

    private function baseUrl(): string
    {
        return $this->isLive() ? 'https://api.tamara.co' : 'https://api-sandbox.tamara.co';
    }

    private function client(): PendingRequest
    {
        return $this->http()->baseUrl($this->baseUrl())->withToken($this->credential('api_token'));
    }
}
