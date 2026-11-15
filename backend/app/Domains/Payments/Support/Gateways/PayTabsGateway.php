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
 * PayTabs PT2 hosted payment page (cards, mada, Apple Pay; 3DS on the
 * hosted page). `cart_amount` is a JSON number with the currency's decimals
 * (3 for OMR/KWD/BHD/JOD), written as an exact literal.
 *
 * API assumptions (to confirm with sandbox keys): one endpoint per region (credential `region`, default from the
 * country: ARE, SAU, OMN, EGY, JOR, else GLOBAL); sandbox and live share it (a test profile is the sandbox);
 * header `authorization: {server_key}`; `POST /payment/request` (tran_type sale, tran_class ecom) → `tran_ref`,
 * `redirect_url`; `POST /payment/query {profile_id, tran_ref}` → `payment_result.response_status` A (approved) |
 * H/P (pending) | D/E/X/V (declined, error, expired, voided); refunds are `POST /payment/request` with
 * tran_type refund and the original `tran_ref` (no idempotency header — `cart_id` carries Nawy's refund key).
 * Callbacks (IPN): header `signature` = hex HMAC-SHA256(raw body, server_key); the event id is the `tran_ref`.
 * The customer returns with a browser POST to the return URL, so that route accepts GET and POST.
 */
class PayTabsGateway extends HttpGateway
{
    /**
     * @var array<string, string>
     */
    public const REGIONS = [
        'ARE' => 'https://secure.paytabs.com',
        'SAU' => 'https://secure.paytabs.sa',
        'OMN' => 'https://secure-oman.paytabs.com',
        'EGY' => 'https://secure-egypt.paytabs.com',
        'JOR' => 'https://secure-jordan.paytabs.com',
        'GLOBAL' => 'https://secure-global.paytabs.com',
    ];

    public function createPayment(PaymentRequest $request): GatewaySession
    {
        $body = $this->call(fn () => $this->client()->withBody($this->jsonWithNumbers([
            'profile_id' => (int) $this->credential('profile_id'),
            'tran_type' => 'sale',
            'tran_class' => 'ecom',
            'cart_id' => $request->reference,
            'cart_currency' => $request->amount->getCurrency()->getCurrencyCode(),
            'cart_amount' => '__AMOUNT__',
            'cart_description' => mb_substr($request->description, 0, 128),
            'paypage_lang' => $request->locale === 'ar' ? 'ar' : 'en',
            'callback' => $request->notifyUrl,
            'return' => $request->returnUrl,
            'hide_shipping' => true,
            'customer_details' => [
                'name' => $request->customerName,
                'email' => $request->customerEmail,
                'phone' => (string) $request->customerPhone,
                'street1' => (string) ($request->shippingAddress['street'] ?? ''),
                'city' => (string) ($request->shippingAddress['city'] ?? ''),
                'country' => $request->countryIso2,
            ],
        ], ['__AMOUNT__' => $this->decimal($request->amount)]), 'application/json')->post('/payment/request'));

        return new GatewaySession(
            (string) ($body['tran_ref'] ?? throw new GatewayError('PayTabs did not return a transaction.')),
            $body['redirect_url'] ?? null,
        );
    }

    public function verify(Payment $payment): GatewayOutcome
    {
        $body = $this->call(fn () => $this->client()->post('/payment/query', [
            'profile_id' => (int) $this->credential('profile_id'),
            'tran_ref' => $payment->gateway_reference,
        ]));

        return $this->outcome($body);
    }

    /**
     * Sale transactions are captured at once.
     */
    public function capture(Payment $payment, Money $amount): GatewayOutcome
    {
        return $this->verify($payment);
    }

    public function refund(Payment $payment, Money $amount, string $idempotencyKey, ?string $reason = null): GatewayRefund
    {
        $body = $this->call(fn () => $this->client()->withBody($this->jsonWithNumbers([
            'profile_id' => (int) $this->credential('profile_id'),
            'tran_type' => 'refund',
            'tran_class' => 'ecom',
            'cart_id' => $idempotencyKey,
            'cart_currency' => $amount->getCurrency()->getCurrencyCode(),
            'cart_amount' => '__AMOUNT__',
            'cart_description' => mb_substr((string) ($reason ?? 'Refund'), 0, 128),
            'tran_ref' => $payment->gateway_reference,
        ], ['__AMOUNT__' => $this->decimal($amount)]), 'application/json')->post('/payment/request'));

        if (data_get($body, 'payment_result.response_status') !== 'A') {
            throw new GatewayError('PayTabs refused the refund: '.data_get($body, 'payment_result.response_message', 'unknown'));
        }

        return new GatewayRefund((string) $body['tran_ref']);
    }

    public function parseWebhook(Request $request): WebhookNotification
    {
        $signature = (string) $request->header('signature');
        $expected = hash_hmac('sha256', $request->getContent(), $this->credential('server_key'));

        if ($signature === '' || ! hash_equals($expected, $signature)) {
            throw new InvalidWebhookSignature('Invalid PayTabs signature.');
        }

        $payload = (array) $request->json()->all();
        $tranRef = (string) ($payload['tran_ref'] ?? throw new InvalidWebhookSignature('PayTabs callback without tran_ref.'));
        $type = strtolower((string) ($payload['tran_type'] ?? 'sale'));

        return new WebhookNotification(
            $tranRef,
            $type,
            $type === 'sale' ? $tranRef : ($payload['previous_tran_ref'] ?? null),
            $type === 'sale' ? ($payload['cart_id'] ?? null) : null,
            $type === 'sale' ? $this->outcome($payload) : null,
            $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    private function outcome(array $transaction): GatewayOutcome
    {
        $reference = $transaction['tran_ref'] ?? null;
        $message = data_get($transaction, 'payment_result.response_message');

        return match (data_get($transaction, 'payment_result.response_status')) {
            'A' => new GatewayOutcome(PaymentStatus::Captured, $reference, isset($transaction['cart_amount']) ? (string) $transaction['cart_amount'] : null),
            'D', 'E', 'X', 'V' => new GatewayOutcome(PaymentStatus::Failed, $reference, failureCode: 'declined', failureMessage: $message),
            default => new GatewayOutcome(PaymentStatus::Pending, $reference),
        };
    }

    private function baseUrl(): string
    {
        $region = $this->config->credential('region') ?? match ($this->config->country?->iso2) {
            'AE' => 'ARE', 'SA' => 'SAU', 'OM' => 'OMN', 'EG' => 'EGY', 'JO' => 'JOR', default => 'GLOBAL',
        };

        return self::REGIONS[strtoupper($region)] ?? self::REGIONS['GLOBAL'];
    }

    private function client(): PendingRequest
    {
        return $this->http()->baseUrl($this->baseUrl())->withHeaders(['authorization' => $this->credential('server_key')]);
    }
}
