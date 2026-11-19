<?php

namespace App\Domains\Payments\Support\Gateways;

use App\Domains\Payments\Enums\PaymentMethod;
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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Deterministic stand-in for every external gateway (driver "fake": tests
 * and local development without keys, risk R4). No network. A payment
 * stays pending until a test scripts the provider answer with
 * {@see willReport()}; failures are scripted with {@see failNext()}.
 * Webhooks are signed `X-Fake-Signature: hex HMAC-SHA256(raw body, secret)`
 * where the secret is the country's `webhook_secret` credential or, when that
 * is empty, `nawy.payments.fake_webhook_secret` (NAWY_FAKE_WEBHOOK_SECRET).
 * There is no built-in fallback: without either, every webhook is rejected.
 */
class FakeGateway implements ChargesSavedCards, PaymentGateway
{
    public const HEADER = 'X-Fake-Signature';

    public function __construct(private PaymentMethod $method, private ?string $webhookSecret = null) {}

    /**
     * Script what the provider reports for a gateway reference.
     */
    public static function willReport(string $gatewayReference, PaymentStatus $status): void
    {
        Cache::forever('fake-gateway:outcome:'.$gatewayReference, $status->value);
    }

    /**
     * Tests (G-A6): the next verify of this payment reports a tokenised card.
     *
     * @param  array{token: string, last4: string, brand?: string|null, exp_month?: int|null, exp_year?: int|null}  $card
     */
    public static function willTokenise(string $gatewayReference, array $card): void
    {
        Cache::forever('fake-gateway:card:'.$gatewayReference, $card);
    }

    /**
     * Tests: the next saved-card charge is declined (or, with
     * `$unreachable`, the provider cannot be reached).
     */
    public static function declineNextCardCharge(bool $unreachable = false): void
    {
        Cache::forever('fake-gateway:token-charge', $unreachable ? 'unreachable' : 'declined');
    }

    /**
     * @return array<int, array{reference: string, amount: string, currency: string, token: string}>
     */
    public static function cardCharges(): array
    {
        return Cache::get('fake-gateway:token-charges', []);
    }

    /**
     * Saved-card charge (merchant-initiated): captured at once unless a test
     * scripted a decline with {@see declineNextCardCharge()}.
     */
    public function chargeSavedCard(PaymentRequest $request, SavedPaymentCard $card): GatewayOutcome
    {
        $scripted = Cache::pull('fake-gateway:token-charge');

        if ($scripted === 'unreachable') {
            throw new GatewayError('Fake gateway is unreachable.');
        }

        $reference = self::referenceFor($request->reference, $this->method);
        Cache::forever('fake-gateway:token-charges', [...self::cardCharges(), [
            'reference' => $request->reference, 'amount' => (string) $request->amount->getAmount(), 'currency' => $request->amount->getCurrency()->getCurrencyCode(), 'token' => (string) $card->gateway_token,
        ]]);

        if ($scripted === 'declined') {
            self::willReport($reference, PaymentStatus::Failed);

            return new GatewayOutcome(PaymentStatus::Failed, $reference, failureCode: 'card_declined', failureMessage: __('The card was declined.'));
        }

        self::willReport($reference, PaymentStatus::Captured);

        return new GatewayOutcome(PaymentStatus::Captured, $reference, (string) $request->amount->getAmount());
    }

    /**
     * Make the next call of a kind ("create" or "refund") fail.
     */
    public static function failNext(string $call): void
    {
        Cache::forever('fake-gateway:fail:'.$call, true);
    }

    /**
     * @return array<int, array{payment: string, amount: string, key: string}>
     */
    public static function refunds(): array
    {
        return Cache::get('fake-gateway:refunds', []);
    }

    public static function referenceFor(string $paymentReference, PaymentMethod $method): string
    {
        return 'fake_'.$method->value.'_'.$paymentReference;
    }

    public function createPayment(PaymentRequest $request): GatewaySession
    {
        $this->failIfScripted('create');
        $reference = self::referenceFor($request->reference, $this->method);

        return new GatewaySession($reference, 'https://pay.fake.test/'.$this->method->value.'/'.$reference, ['amount' => (string) $request->amount->getAmount()]);
    }

    public function verify(Payment $payment): GatewayOutcome
    {
        $status = PaymentStatus::from(Cache::get('fake-gateway:outcome:'.$payment->gateway_reference, PaymentStatus::Pending->value));

        return new GatewayOutcome($status, $payment->gateway_reference, $status->isSuccessful() ? (string) $payment->amount : null,
            $status === PaymentStatus::Failed ? 'declined' : null,
            $status === PaymentStatus::Failed ? __('The card was declined.') : null,
            $status->isSuccessful() ? Cache::get('fake-gateway:card:'.$payment->gateway_reference) : null);
    }

    public function capture(Payment $payment, Money $amount): GatewayOutcome
    {
        self::willReport((string) $payment->gateway_reference, PaymentStatus::Captured);

        return new GatewayOutcome(PaymentStatus::Captured, $payment->gateway_reference, (string) $amount->getAmount());
    }

    public function refund(Payment $payment, Money $amount, string $idempotencyKey, ?string $reason = null): GatewayRefund
    {
        $this->failIfScripted('refund');
        Cache::forever('fake-gateway:refunds', [...self::refunds(), ['payment' => $payment->reference, 'amount' => (string) $amount->getAmount(), 'key' => $idempotencyKey]]);

        return new GatewayRefund('fake_refund_'.$idempotencyKey);
    }

    /**
     * Body: {"id": event id, "type": …, "gateway_reference": …, "status": pending|authorized|captured|failed|expired}.
     */
    public function parseWebhook(Request $request): WebhookNotification
    {
        $secret = self::secret($this->webhookSecret);

        if ($secret === null) {
            throw new InvalidWebhookSignature('The fake gateway has no webhook secret configured (credential webhook_secret or NAWY_FAKE_WEBHOOK_SECRET).');
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        $signature = (string) $request->header(self::HEADER);

        if ($signature === '' || ! hash_equals($expected, $signature)) {
            throw new InvalidWebhookSignature('Invalid fake gateway signature.');
        }

        $payload = (array) $request->json()->all();
        $status = PaymentStatus::tryFrom((string) ($payload['status'] ?? ''));

        return new WebhookNotification(
            (string) ($payload['id'] ?? throw new InvalidWebhookSignature('Fake event without id.')),
            (string) ($payload['type'] ?? 'payment.updated'),
            $payload['gateway_reference'] ?? null,
            $payload['payment_reference'] ?? null,
            $status === null ? null : new GatewayOutcome($status, $payload['gateway_reference'] ?? null, $payload['amount'] ?? null),
            $payload,
        );
    }

    /**
     * Sign a body the way this fake gateway expects (test helper).
     */
    public static function sign(string $body, ?string $secret = null): string
    {
        return hash_hmac('sha256', $body, self::secret($secret) ?? throw new \LogicException('Set nawy.payments.fake_webhook_secret to sign fake webhooks.'));
    }

    /**
     * The signing secret: the given credential, else the configured one; null when neither is set.
     */
    private static function secret(?string $credential): ?string
    {
        $secret = $credential ?: (string) config('nawy.payments.fake_webhook_secret');

        return $secret === '' ? null : $secret;
    }

    private function failIfScripted(string $call): void
    {
        if (Cache::pull('fake-gateway:fail:'.$call)) {
            throw new GatewayError("The {$this->method->value} gateway is unavailable (fake).");
        }
    }
}
