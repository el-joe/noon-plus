<?php

namespace App\Domains\Cart\Support;

use App\Domains\Shipping\Enums\ShippingMethod;
use App\Domains\Shipping\Support\DeliveryEstimate;
use Brick\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Totals for a cart (FR-CHK-2). All amounts are VAT-inclusive money in the
 * country currency; `groups` holds one entry per seller (future sub-order).
 *
 * Per group: `shipping_cost` is the engine's price for the chosen method,
 * `shipping` what the customer pays for it, `shipping_absorbed` what the
 * platform pays because the order reached the free-shipping threshold, and
 * `shipping_subsidy` the exceptional-zone subsidy (seller share + platform
 * share). The group `total` is the seller's part (goods − discount +
 * shipping); warranty plans are platform revenue and sit outside it.
 */
final readonly class CheckoutQuote
{
    /**
     * @param  array<int, array{vendor_id: int, vendor_name: string, lines: array<int, array{item_id: int, listing_id: int, category_id: int, sku: string, title: string, quantity: int, unit_price: Money, regular_unit_price: Money, line_total: Money, flash_sale_submission_id: int|null, flash_sale_ends_at: CarbonImmutable|null, flash_limit_exceeded: bool, warranty_plan_id: int|null, warranty_name: string|null, warranty_months: int|null, warranty_unit_price: Money, warranty_total: Money, warranty_options: array<int, array{id: int, name: string, months: int, price: Money}>}>, subtotal: Money, discount: Money, shipping: Money, shipping_method: ShippingMethod, shipping_zone_id: int|null, shipping_cost: Money, shipping_absorbed: Money, shipping_subsidy: Money, shipping_subsidy_vendor: Money, shipping_subsidy_platform: Money, shipping_options: array<int, array{method: ShippingMethod, fee: Money, eta: DeliveryEstimate}>, eta: DeliveryEstimate, warranty: Money, total: Money}>  $groups
     * @param  array<int, array{coupon_id: int, code: string, scope: string, discount: Money, free_shipping: bool}>  $coupons
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public array $groups,
        public Money $subtotal,
        public Money $discountTotal,
        public Money $shippingTotal,
        public Money $codFee,
        public Money $grandTotal,
        public Money $taxIncluded,
        public string $taxRate,
        public array $coupons,
        public array $warnings,
        public Money $warrantyTotal,
        public Money $shippingSubsidyTotal,
        public Money $shippingAbsorbedTotal,
    ) {}

    public function isEmpty(): bool
    {
        return $this->groups === [];
    }

    /**
     * True when a line wants a flash price beyond the customer's maximum or
     * the units left; checkout refuses such a cart.
     */
    public function exceedsFlashLimits(): bool
    {
        foreach ($this->groups as $group) {
            foreach ($group['lines'] as $line) {
                if ($line['flash_limit_exceeded']) {
                    return true;
                }
            }
        }

        return false;
    }
}
