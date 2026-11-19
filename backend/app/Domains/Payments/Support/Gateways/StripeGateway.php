<?php

namespace App\Domains\Payments\Support\Gateways;

use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Exceptions\GatewayError;
use App\Domains\Payments\Exceptions\InvalidWebhookSignature;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Models\SavedPaymentCard;
use App\Domains\Payments\Support\Data\GatewayOutcome;
use App\Domains\Payments\Support\Data\GatewayRefund;
use App\Domains\Payments\Support\Data\GatewaySession;
use App\Domains\Payments\Support\Data\PaymentRequest;
use App\Domains\Payments\Support\Data\WebhookNotification;
use Brick\Money\Money;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;

/**
 * Stripe Checkout (hosted page, 3DS handled by Stripe).
 *
 * Amounts are sent in the currency's minor unit. For the 3-decimal
 * currencies (OMR, KWD, BHD, JOD) Stripe requires the last minor digit to
 * be 0 (amounts in multiples of 10 fils/baisa); such amounts are refused
 * here and hidden from the checkout list ({@see acceptsAmount()}).
 *
 * API assumptions (to confirm with sandbox keys): `https://api.stripe.com`, bearer secret key (test vs live is the
 * key), form-encoded `POST /v1/checkout/sessions` with an `Idempotency-Key` header; `GET /v1/checkout/sessions/{id}`
 * → `payment_status` paid | unpaid | no_payment_required, `status` open | complete | expired, `payment_intent`,
 * `amount_total`; `POST /v1/refunds {payment_intent, amount}` with `Idempotency-Key`.
 * Webhooks: `Stripe-Signature: t={unix},v1={hex}` where v1 = HMAC-SHA256(`{t}.{raw body}`, `whsec_…` secret);
 * events older than 5 minutes are refused (replay protection). Event id = `evt_…`.
 * Saved cards (merchant-initiated, e.g. vendor plan auto-renewal): `POST /v1/payment_intents` with `customer`
 * (`gateway_customer_ref`), `payment_method` (the token), `off_session=true`, `confirm=true`; the gateway reference
 * is then the PaymentIntent id (`pi_…`), verified with `GET /v1/payment_intents/{id}` (`status` succeeded |
 * processing | requires_action | requires_payment_method | canceled, `amount_received`) and refunded by id.
 */
class StripeGateway extends HttpGateway implements ChargesSavedCards
{
    public const SIGNATURE_TOLERANCE_SECONDS = 300;

    public function createPayment(PaymentRequest $request): GatewaySession
    {
        if (! self::acceptsAmount($request->amount)) {
            throw new GatewayError('Stripe needs 3-decimal amounts in multiples of 0.010.');
        }

        $body = $this->call(fn () => $this->client()->asForm()->withHeaders(['Idempotency-Key' => $request->idempotencyKey])->post('/v1/checkout/sessions', [
            'mode' => 'payment',
            'success_url' => $request->returnUrl,
            'cancel_url' => $request->cancelUrl,
            'client_reference_id' => $request->reference,
            'customer_email' => $request->customerEmail,
            'locale' => in_array($request->locale, ['ar', 'en'], true) ? $request->locale : 'auto',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($request->amount->getCurrency()->getCurrencyCode()),
                    'unit_amount' => $this->minorUnits($request->amount),
                    'product_data' => ['name' => $request->description],
                ],
            ]],
            'metadata' => ['payment_reference' => $request->reference, 'order_number' => $request->orderNumber],
            'payment_intent_data' => ['metadata' => ['payment_reference' => $request->reference]],
        ]));

        return new GatewaySession((string) ($body['id'] ?? throw new GatewayError('Stripe did not return a session.')), $body['url'] ?? null);
    }

    public function verify(Payment $payment): GatewayOutcome
    {
        if (str_starts_with((string) $payment->gateway_reference, 'pi_')) {
            return $this->outcomeFromIntent($this->call(fn () => $this->client()->get('/v1/payment_intents/'.$payment->gateway_reference)));
        }

        return $this->outcomeFromSession($this->session($payment));
    }

    public function chargeSavedCard(PaymentRequest $request, SavedPaymentCard $card): GatewayOutcome
    {
        if (! self::acceptsAmount($request->amount)) {
            throw new GatewayError('Stripe needs 3-decimal amounts in multiples of 0.010.');
        }

        $body = $this->call(fn () => $this->client()->asForm()->withHeaders(['Idempotency-Key' => $request->idempotencyKey])->post('/v1/payment_intents', array_filter([
            'amount' => $this->minorUnits($request->amount),
            'currency' => strtolower($request->amount->getCurrency()->getCurrencyCode()),
            'customer' => $card->gateway_customer_ref,
            'payment_method' => $card->gateway_token,
            'off_session' => 'true',
            'confirm' => 'true',
            'description' => $request->description,
            'metadata' => ['payment_reference' => $request->reference, 'order_number' => $request->orderNumber],
        ], fn (mixed $value): bool => $value !== null)));

        return $this->outcomeFromIntent($body);
    }

    /**
     * Checkout sessions capture automatically.
     */
    public function capture(Payment $payment, Money $amount): GatewayOutcome
    {
        return $this->verify($payment);
    }

    public function refund(Payment $payment, Money $amount, string $idempotencyKey, ?string $reason = null): GatewayRefund
    {
        $paymentIntent = str_starts_with((string) $payment->gateway_reference, 'pi_')
            ? $payment->gateway_reference
            : ($this->session($payment)['payment_intent'] ?? throw new GatewayError('Stripe session has no payment to refund.'));

        $body = $this->call(fn () => $this->client()->asForm()->withHeaders(['Idempotency-Key' => $idempotencyKey])->post('/v1/refunds', [
            'payment_intent' => is_array($paymentIntent) ? $paymentIntent['id'] : $paymentIntent,
            'amount' => $this->minorUnits($amount),
            'metadata' => ['payment_reference' => $payment->reference, 'reason' => (string) $reason],
        ]));

        return new GatewayRefund((string) $body['id'], ($body['status'] ?? null) === 'succeeded');
    }

    public function parseWebhook(Request $request): WebhookNotification
    {
        $header = (string) $request->header('Stripe-Signature');
        $parts = [];

        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$key][] = $value;
        }

        $timestamp = (int) ($parts['t'][0] ?? 0);
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $this->credential('webhook_secret'));
        $matches = array_filter($parts['v1'] ?? [], fn (string $signature): bool => hash_equals($expected, $signature));

        if ($timestamp === 0 || $matches === [] || abs(now()->getTimestamp() - $timestamp) > self::SIGNATURE_TOLERANCE_SECONDS) {
            throw new InvalidWebhookSignature('Invalid Stripe signature.');
        }

        $payload = (array) $request->json()->all();
        $type = (string) ($payload['type'] ?? 'unknown');
        $object = (array) data_get($payload, 'data.object', []);
        $isSession = ($object['object'] ?? null) === 'checkout.session';

        $outcome = match (true) {
            ! $isSession => null,
            $type === 'checkout.session.completed', $type === 'checkout.session.async_payment_succeeded' => $this->outcomeFromSession($object),
            $type === 'checkout.session.async_payment_failed' => new GatewayOutcome(PaymentStatus::Failed, $object['id'] ?? null, failureCode: 'async_payment_failed'),
            $type === 'checkout.session.expired' => new GatewayOutcome(PaymentStatus::Expired, $object['id'] ?? null),
            default => null,
        };

        return new WebhookNotification(
            (string) ($payload['id'] ?? throw new InvalidWebhookSignature('Stripe event without id.')),
            $type,
            $isSession ? ($object['id'] ?? null) : null,
            $object['client_reference_id'] ?? data_get($object, 'metadata.payment_reference'),
            $outcome,
            $payload,
        );
    }

    /**
     * Stripe takes 3-decimal currencies only in multiples of 10 minor units.
     */
    public static function acceptsAmount(Money $amount): bool
    {
        return $amount->getCurrency()->getDefaultFractionDigits() !== 3
            || $amount->getMinorAmount()->toInt() % 10 === 0;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function outcomeFromSession(array $session): GatewayOutcome
    {
        $id = $session['id'] ?? null;

        return match (true) {
            ($session['payment_status'] ?? null) === 'paid' => new GatewayOutcome(
                PaymentStatus::Captured, $id,
                isset($session['amount_total']) ? $this->fromMinorUnits((int) $session['amount_total'], strtoupper((string) $session['currency'])) : null,
            ),
            ($session['status'] ?? null) === 'expired' => new GatewayOutcome(PaymentStatus::Expired, $id),
            default => new GatewayOutcome(PaymentStatus::Pending, $id),
        };
    }

    /**
     * A PaymentIntent (saved-card charge): succeeded = captured; one that
     * needs the cardholder or a new card has failed for this attempt.
     *
     * @param  array<string, mixed>  $intent
     */
    private function outcomeFromIntent(array $intent): GatewayOutcome
    {
        $id = $intent['id'] ?? null;

        return match ($intent['status'] ?? null) {
            'succeeded' => new GatewayOutcome(PaymentStatus::Captured, $id,
                isset($intent['amount_received']) ? $this->fromMinorUnits((int) $intent['amount_received'], strtoupper((string) $intent['currency'])) : null),
            'processing' => new GatewayOutcome(PaymentStatus::Pending, $id),
            'canceled' => new GatewayOutcome(PaymentStatus::Failed, $id, failureCode: 'canceled'),
            default => new GatewayOutcome(PaymentStatus::Failed, $id, failureCode: (string) (data_get($intent, 'last_payment_error.code') ?? $intent['status'] ?? 'declined'),
                failureMessage: data_get($intent, 'last_payment_error.message') ?? __('The saved card needs the cardholder; pay the invoice by card instead.')),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function session(Payment $payment): array
    {
        return $this->call(fn () => $this->client()->get('/v1/checkout/sessions/'.$payment->gateway_reference));
    }

    private function client(): PendingRequest
    {
        return $this->http()->baseUrl('https://api.stripe.com')->withToken($this->credential('secret_key'));
    }
}
