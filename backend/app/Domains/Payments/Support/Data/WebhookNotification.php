<?php

namespace App\Domains\Payments\Support\Data;

/**
 * A verified gateway notification. `eventId` is unique per provider event
 * (used for idempotency); the payment is found by the provider reference or,
 * when the provider echoes it, by Nawy's own payment reference. `outcome`
 * is null for events Nawy does not act on.
 */
final readonly class WebhookNotification
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $eventId,
        public string $type,
        public ?string $gatewayReference,
        public ?string $paymentReference,
        public ?GatewayOutcome $outcome,
        public array $payload,
    ) {}
}
