<?php

namespace App\Domains\Payments\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Enums\WebhookEventStatus;
use App\Domains\Payments\Exceptions\InvalidWebhookSignature;
use App\Domains\Payments\Exceptions\PaymentMethodUnavailable;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Models\PaymentWebhookEvent;
use App\Domains\Payments\Support\Gateways\GatewayFactory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Throwable;

/**
 * Gateway notifications (ADR 6, risk T9): the signature is verified by the
 * adapter, the event is stored once per (gateway, provider event id) — a
 * redelivery is reported as a duplicate and changes nothing (unless the
 * first delivery failed, then it is processed again) — and the outcome goes
 * through the payment state machine, which ignores backward moves.
 */
class WebhookProcessor
{
    public function __construct(
        private CountryContext $countryContext,
        private PaymentMethodCatalog $catalog,
        private GatewayFactory $gateways,
        private PaymentAttempts $attempts,
    ) {}

    /**
     * @return 'processed'|'ignored'|'duplicate'
     *
     * @throws InvalidWebhookSignature|PaymentMethodUnavailable
     */
    public function handle(PaymentMethod $gateway, Request $request): string
    {
        $config = $this->catalog->config($this->countryContext->current(), $gateway)
            ?? throw new PaymentMethodUnavailable("{$gateway->value} is not configured for this country.");

        $notification = $this->gateways->make($gateway, $config)->parseWebhook($request);

        try {
            $event = PaymentWebhookEvent::query()->create([
                'gateway' => $gateway,
                'event_id' => $notification->eventId,
                'type' => mb_substr($notification->type, 0, 100),
                'status' => WebhookEventStatus::Received,
                'payload' => $notification->payload,
            ]);
        } catch (UniqueConstraintViolationException) {
            $event = PaymentWebhookEvent::query()->where('gateway', $gateway)->where('event_id', $notification->eventId)->firstOrFail();

            if ($event->status !== WebhookEventStatus::Failed) {
                return 'duplicate';
            }
        }

        $payment = Payment::query()
            ->where('method', $gateway)
            ->where(fn ($query) => $query
                ->when($notification->gatewayReference !== null, fn ($query) => $query->orWhere('gateway_reference', $notification->gatewayReference))
                ->when($notification->paymentReference !== null, fn ($query) => $query->orWhere('reference', $notification->paymentReference)))
            ->when($notification->gatewayReference === null && $notification->paymentReference === null, fn ($query) => $query->whereRaw('1 = 0'))
            ->first();

        if ($payment === null || $notification->outcome === null) {
            $event->forceFill(['status' => WebhookEventStatus::Ignored, 'payment_id' => $payment?->id, 'error' => $payment === null ? 'payment not found' : null, 'processed_at' => now()])->save();

            return 'ignored';
        }

        try {
            $this->attempts->applyOutcome($payment, $notification->outcome, 'webhook');
        } catch (Throwable $exception) {
            $event->forceFill(['status' => WebhookEventStatus::Failed, 'payment_id' => $payment->id, 'error' => mb_substr($exception->getMessage(), 0, 255)])->save();

            throw $exception;
        }

        $event->forceFill(['status' => WebhookEventStatus::Processed, 'payment_id' => $payment->id, 'error' => null, 'processed_at' => now()])->save();

        return 'processed';
    }
}
