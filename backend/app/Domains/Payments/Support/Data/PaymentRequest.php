<?php

namespace App\Domains\Payments\Support\Data;

use Brick\Money\Money;

/**
 * What an adapter needs to start a payment. `reference` is Nawy's payment
 * reference (sent to the gateway as merchant reference / metadata), the
 * idempotency key protects the create call against retries.
 */
final readonly class PaymentRequest
{
    /**
     * @param  array<string, mixed>  $shippingAddress
     */
    public function __construct(
        public string $reference,
        public string $orderNumber,
        public Money $amount,
        public string $description,
        public string $customerEmail,
        public string $customerName,
        public ?string $customerPhone,
        public string $countryIso2,
        public string $locale,
        public string $returnUrl,
        public string $cancelUrl,
        public string $notifyUrl,
        public string $idempotencyKey,
        public array $shippingAddress = [],
    ) {}
}
