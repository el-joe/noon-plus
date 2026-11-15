<?php

namespace App\Domains\Payments\Support\Data;

/**
 * Result of starting a payment: the provider's id for it and the next action
 * for the customer (a redirect to the hosted page / 3DS, or client data for
 * an SDK). A method without a next action (COD) has neither.
 */
final readonly class GatewaySession
{
    /**
     * @param  array<string, mixed>  $clientData
     */
    public function __construct(
        public ?string $gatewayReference,
        public ?string $redirectUrl = null,
        public array $clientData = [],
    ) {}
}
