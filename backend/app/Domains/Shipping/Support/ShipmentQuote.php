<?php

namespace App\Domains\Shipping\Support;

use App\Domains\Shipping\Enums\ShippingMethod;
use Brick\Money\Money;

/**
 * The delivery offer for one seller's shipment: the chosen method, its cost
 * before any free-shipping or subsidy, the ETA and the other methods the
 * customer could pick. `usesFallback` means no zone/rate matched and the flat
 * per-seller fee applied.
 */
final readonly class ShipmentQuote
{
    /**
     * @param  array<int, array{method: ShippingMethod, fee: Money, eta: DeliveryEstimate}>  $options
     */
    public function __construct(
        public ShippingMethod $method,
        public ?int $zoneId,
        public Money $cost,
        public DeliveryEstimate $eta,
        public bool $subsidyEligible,
        public string $vendorSubsidySharePercent,
        public array $options,
        public bool $usesFallback,
    ) {}
}
