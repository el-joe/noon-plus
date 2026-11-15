<?php

namespace App\Domains\Payments\Support\Data;

/**
 * Provider answer to a refund call. `completed` false means the provider
 * accepted it but settles it later (Nawy still records it as completed on
 * our side once the call succeeds; the refund reaches the card in 5–10 days).
 */
final readonly class GatewayRefund
{
    public function __construct(
        public string $refundReference,
        public bool $completed = true,
    ) {}
}
