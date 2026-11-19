<?php

namespace App\Domains\Payments\Support\Data;

use App\Domains\Payments\Enums\PaymentStatus;

/**
 * What the provider says about a payment (verify, capture or webhook):
 * one of pending, authorized, captured, failed or expired. `amount` is the
 * captured or authorised amount in major units as reported by the provider
 * (already converted back from minor units by the adapter). `card` is the
 * gateway's reusable card token and display details when the provider
 * tokenised the card (G-A6) — never the card number.
 */
final readonly class GatewayOutcome
{
    public function __construct(
        public PaymentStatus $status,
        public ?string $gatewayReference = null,
        public ?string $amount = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        /** @var array{token: string, last4: string, brand?: string|null, exp_month?: int|null, exp_year?: int|null, customer_ref?: string|null}|null */
        public ?array $card = null,
    ) {}
}
