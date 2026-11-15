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
use App\Shared\Money\MoneyFactory;
use Brick\Money\Money;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;

/**
 * Thawani Pay (Oman, OMR only) — hosted checkout sessions.
 *
 * Thawani takes amounts in **baisa** (1 OMR = 1000 baisa, integer). That
 * conversion happens here and nowhere else (SRS FR-CHK-3, risk F3).
 *
 * API assumptions (to confirm with sandbox keys):
 * - Base URL `https://uatcheckout.thawani.om` (sandbox) / `https://checkout.thawani.om` (live), header `thawani-api-key: {secret_key}`.
 * - `POST /api/v1/checkout/session` → `data.session_id`; the customer pays at `/pay/{session_id}?key={publishable_key}`.
 * - `GET /api/v1/checkout/session/{id}` → `data.payment_status` paid | unpaid | cancelled, `data.invoice`, `data.total_amount` (baisa).
 * - `GET /api/v1/payments?checkout_invoice=…` → the payment id; `POST /api/v1/refunds {payment_id, reason}` refunds the
 *   **whole** payment (no amount field), so partial refunds are refused here.
 * - Webhooks: headers `thawani-signature` = hex HMAC-SHA256(`{raw body}-{thawani-timestamp}`, webhook secret) and
 *   `thawani-timestamp`; body `{event_type, data:{session_id, client_reference_id, payment_status, …}}`. There is no
 *   event id, so the idempotency id is `{event_type}:{session_id|id}`.
 */
class ThawaniGateway extends HttpGateway
{
    /**
     * Replay window for signed notifications (QC-048), as for Stripe; the
     * event id idempotency of the webhook processor still applies.
     */
    public const SIGNATURE_TOLERANCE_SECONDS = 300;

    public function createPayment(PaymentRequest $request): GatewaySession
    {
        $body = $this->call(fn () => $this->client()->post('/api/v1/checkout/session', [
            'client_reference_id' => $request->reference,
            'mode' => 'payment',
            'products' => [[
                'name' => mb_substr($request->description, 0, 40),
                'quantity' => 1,
                'unit_amount' => self::toBaisa($request->amount),
            ]],
            'success_url' => $request->returnUrl,
            'cancel_url' => $request->cancelUrl,
            'metadata' => ['payment_reference' => $request->reference, 'order_number' => $request->orderNumber],
        ]));

        $sessionId = (string) (data_get($body, 'data.session_id') ?? throw new GatewayError('Thawani did not return a checkout session.'));

        return new GatewaySession(
            $sessionId,
            $this->baseUrl().'/pay/'.$sessionId.'?key='.urlencode($this->credential('publishable_key')),
        );
    }

    public function verify(Payment $payment): GatewayOutcome
    {
        $session = (array) data_get($this->call(fn () => $this->client()->get('/api/v1/checkout/session/'.$payment->gateway_reference)), 'data', []);

        return match ($session['payment_status'] ?? null) {
            'paid' => new GatewayOutcome(PaymentStatus::Captured, $payment->gateway_reference, self::fromBaisa((int) ($session['total_amount'] ?? 0))),
            'cancelled' => new GatewayOutcome(PaymentStatus::Failed, $payment->gateway_reference, failureCode: 'cancelled', failureMessage: __('The payment was cancelled.')),
            default => new GatewayOutcome(PaymentStatus::Pending, $payment->gateway_reference),
        };
    }

    /**
     * Thawani captures when the customer pays; nothing to capture later.
     */
    public function capture(Payment $payment, Money $amount): GatewayOutcome
    {
        return $this->verify($payment);
    }

    public function refund(Payment $payment, Money $amount, string $idempotencyKey, ?string $reason = null): GatewayRefund
    {
        if (! $amount->isEqualTo(app(MoneyFactory::class)->of((string) $payment->captured_amount, $payment->currency))) {
            throw new GatewayError('Thawani refunds whole payments only.');
        }

        $invoice = data_get($this->call(fn () => $this->client()->get('/api/v1/checkout/session/'.$payment->gateway_reference)), 'data.invoice');
        $paymentId = data_get($this->call(fn () => $this->client()->get('/api/v1/payments', ['checkout_invoice' => $invoice, 'limit' => 1, 'skip' => 0])), 'data.0.payment_id')
            ?? throw new GatewayError('Thawani payment not found for refund.');

        $body = $this->call(fn () => $this->client()->post('/api/v1/refunds', [
            'payment_id' => $paymentId,
            'reason' => $reason ?? 'Refund',
            'metadata' => ['refund_key' => $idempotencyKey, 'payment_reference' => $payment->reference],
        ]));

        return new GatewayRefund((string) (data_get($body, 'data.refund_id') ?? data_get($body, 'data.id') ?? $idempotencyKey));
    }

    public function parseWebhook(Request $request): WebhookNotification
    {
        $signature = (string) $request->header('thawani-signature');
        $timestamp = (string) $request->header('thawani-timestamp');
        $expected = hash_hmac('sha256', $request->getContent().'-'.$timestamp, $this->credential('webhook_secret'));

        if ($signature === '' || $timestamp === '' || ! hash_equals($expected, $signature)) {
            throw new InvalidWebhookSignature('Invalid Thawani signature.');
        }

        $sentAt = ctype_digit($timestamp) ? (int) $timestamp : 0;
        $sentAt = $sentAt > 100_000_000_000 ? intdiv($sentAt, 1000) : $sentAt;

        if (abs(now()->getTimestamp() - $sentAt) > self::SIGNATURE_TOLERANCE_SECONDS) {
            throw new InvalidWebhookSignature('Stale Thawani notification (outside the replay window).');
        }

        $payload = (array) $request->json()->all();
        $type = (string) ($payload['event_type'] ?? 'unknown');
        $data = (array) ($payload['data'] ?? []);
        $sessionId = $data['session_id'] ?? null;

        $outcome = match ($data['payment_status'] ?? null) {
            'paid' => new GatewayOutcome(PaymentStatus::Captured, $sessionId, isset($data['total_amount']) ? self::fromBaisa((int) $data['total_amount']) : null),
            'cancelled', 'failed' => new GatewayOutcome(PaymentStatus::Failed, $sessionId, failureCode: (string) $data['payment_status']),
            default => null,
        };

        return new WebhookNotification(
            $type.':'.($sessionId ?? $data['id'] ?? sha1($request->getContent())),
            $type,
            $sessionId,
            $data['client_reference_id'] ?? null,
            $outcome,
            $payload,
        );
    }

    /**
     * OMR → baisa (×1000, integer). Only OMR is accepted.
     */
    public static function toBaisa(Money $amount): int
    {
        if ($amount->getCurrency()->getCurrencyCode() !== 'OMR') {
            throw new GatewayError('Thawani only accepts OMR.');
        }

        return $amount->getAmount()->multipliedBy(1000)->toInt();
    }

    /**
     * Baisa → OMR as a 3-decimal string.
     */
    public static function fromBaisa(int $baisa): string
    {
        return (string) Money::ofMinor($baisa, 'OMR')->getAmount();
    }

    private function baseUrl(): string
    {
        return $this->isLive() ? 'https://checkout.thawani.om' : 'https://uatcheckout.thawani.om';
    }

    private function client(): PendingRequest
    {
        return $this->http()->baseUrl($this->baseUrl())->withHeaders(['thawani-api-key' => $this->credential('secret_key')]);
    }
}
