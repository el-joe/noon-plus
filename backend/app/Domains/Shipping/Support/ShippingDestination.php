<?php

namespace App\Domains\Shipping\Support;

/**
 * Where a cart ships to. Both parts are optional: without a city the
 * country's default zone (or the flat fallback fee) applies.
 */
final readonly class ShippingDestination
{
    public function __construct(
        public ?string $city = null,
        public ?string $area = null,
    ) {}

    /**
     * @param  array<string, mixed>  $address
     */
    public static function fromAddress(array $address): self
    {
        return new self(
            isset($address['city']) ? trim((string) $address['city']) : null,
            isset($address['area']) ? trim((string) $address['area']) : null,
        );
    }
}
