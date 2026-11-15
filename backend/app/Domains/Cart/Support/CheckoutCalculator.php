<?php

namespace App\Domains\Cart\Support;

use App\Domains\Advertising\Support\CouponParticipations;
use App\Domains\Cart\Models\Cart;
use App\Domains\Catalog\Support\Measurements;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Coupons\Enums\CouponScope;
use App\Domains\Coupons\Enums\CouponType;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Coupons\Support\CouponTargets;
use App\Domains\Coupons\Support\CouponValidator;
use App\Domains\FlashSales\Support\FlashSalePricing;
use App\Domains\Listings\Models\Listing;
use App\Domains\Membership\Support\Memberships;
use App\Domains\Settings\SettingsService;
use App\Domains\Shipping\Enums\ShippingMethod;
use App\Domains\Shipping\Support\ShipmentQuoter;
use App\Domains\Shipping\Support\ShippingDestination;
use App\Domains\Shipping\Support\ZoneResolver;
use App\Domains\Warranty\Support\WarrantyPlanCatalog;
use App\Shared\Money\MoneyFactory;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Support\Collection;

/**
 * Cart totals (FR-CHK-2): subtotal (flash prices while a deal runs)
 * − coupons (≤ 1 Nawy + 1 seller) + shipping per seller from the shipping
 * engine (zone → method → rate/slabs → surcharges; free over the threshold
 * or for Nawy One members, absorbed by the platform; exceptional-zone subsidy) + COD fee + warranty
 * plans. Prices include VAT; the included VAT is shown separately. Every
 * amount is rounded to the currency. Without a matching zone or rate the
 * flat `shipping_fee_per_seller` applies, as before the engine existed.
 */
class CheckoutCalculator
{
    public function __construct(
        private CountryContext $countryContext,
        private MoneyFactory $money,
        private SettingsService $settings,
        private ZoneResolver $zones,
        private ShipmentQuoter $shipments,
        private FlashSalePricing $flashPricing,
        private WarrantyPlanCatalog $warrantyPlans,
        private CouponTargets $couponTargets,
    ) {}

    public function quote(Cart $cart, ?string $paymentMethod = null, ?ShippingDestination $destination = null): CheckoutQuote
    {
        $country = $this->countryContext->current();
        $currency = $country->currency;
        $zero = $this->money->zero($currency);
        $defaults = config("nawy.checkout.defaults.{$country->code}");
        $setting = fn (string $key): string => (string) $this->settings->get("checkout.{$key}", $defaults[$key], $country);
        $now = now();
        $warnings = [];

        $groups = [];
        $items = $cart->items()->with(['listing.vendor', 'listing.variant.product.media', 'warrantyPlan'])->get();
        $deals = $this->flashPricing->activeDeals($items->pluck('listing_id')->all());
        $purchased = $this->flashPricing->purchasedBy($cart->customer_user_id, $deals->pluck('id')->all());
        $plansByCategory = $this->warrantyPlans->forCategories($items->map(fn ($item): int => (int) $item->listing->variant->product->category_id)->all());

        foreach ($items as $item) {
            $listing = $item->listing;
            $categoryId = (int) $listing->variant->product->category_id;
            $regularPrice = $this->money->of($listing->effectivePrice(), $currency);
            $unitPrice = $regularPrice;
            $deal = $deals->get($listing->id);
            $flashLimitExceeded = false;
            $flashApplied = false;

            if ($deal !== null) {
                $allowance = min($deal->remainingQuantity(), $deal->event->max_qty_per_customer - ($purchased[$deal->id] ?? 0));

                if ($item->quantity <= $allowance) {
                    $unitPrice = $this->money->of((string) $deal->flash_price, $currency);
                    $flashApplied = true;
                } else {
                    $flashLimitExceeded = true;
                    $warnings[] = __('The deal price for :title is limited to :count; lower the quantity to get it.', ['title' => $listing->variant->product->title(), 'count' => max(0, $allowance)]);
                }
            }

            $plans = $plansByCategory->get($categoryId, collect());
            $plan = $item->warrantyPlan !== null && $plans->contains('id', $item->warranty_plan_id) ? $item->warrantyPlan : null;

            if ($item->warranty_plan_id !== null && $plan === null) {
                $warnings[] = __('The warranty plan for :title is no longer available and was removed from the total.', ['title' => $listing->variant->product->title()]);
            }

            $warrantyUnitPrice = $plan === null ? $zero : $this->money->of((string) $plan->price, $currency);

            $groups[$listing->vendor_id] ??= [
                'vendor_id' => $listing->vendor_id,
                'vendor_name' => $listing->vendor->store_name,
                'lines' => [],
                'listings' => [],
                'subtotal' => $zero,
                'discount' => $zero,
                'shipping' => $zero,
                'warranty' => $zero,
                'total' => $zero,
            ];
            $lineTotal = $unitPrice->multipliedBy($item->quantity);
            $warrantyTotal = $warrantyUnitPrice->multipliedBy($item->quantity);
            $groups[$listing->vendor_id]['lines'][] = [
                'item_id' => $item->id,
                'listing_id' => $listing->id,
                'category_id' => $categoryId,
                'sku' => $listing->variant->sku,
                'title' => $listing->variant->product->title(),
                'product_slug' => $listing->variant->product->slug,
                'image' => $listing->variant->product->primaryImage($listing->product_variant_id)?->toPublicArray(),
                'quantity' => $item->quantity,
                'unit_price' => $unitPrice,
                'regular_unit_price' => $regularPrice,
                'line_total' => $lineTotal,
                'flash_sale_submission_id' => $flashApplied ? $deal->id : null,
                'flash_sale_ends_at' => $flashApplied ? $deal->event->ends_at : null,
                'flash_limit_exceeded' => $flashLimitExceeded,
                'warranty_plan_id' => $plan?->id,
                'warranty_name' => $plan?->name(),
                'warranty_months' => $plan?->duration_months,
                'warranty_unit_price' => $warrantyUnitPrice,
                'warranty_total' => $warrantyTotal,
                'fulfilment_mode' => $listing->variant->product->fulfilment_mode,
                'measurements' => $item->measurements,
                'measurement_note' => $item->measurement_note,
                'measurement_fields' => $listing->variant->product->fulfilment_mode === 'made_to_order' ? app(Measurements::class)->describe($listing->variant->product) : [],
                'warranty_options' => $plans->map(fn ($option): array => [
                    'id' => $option->id,
                    'name' => $option->name(),
                    'months' => $option->duration_months,
                    'price' => $this->money->of((string) $option->price, $currency),
                ])->all(),
            ];
            $groups[$listing->vendor_id]['listings'][] = ['listing' => $listing, 'quantity' => $item->quantity];
            $groups[$listing->vendor_id]['subtotal'] = $groups[$listing->vendor_id]['subtotal']->plus($lineTotal);
            $groups[$listing->vendor_id]['warranty'] = $groups[$listing->vendor_id]['warranty']->plus($warrantyTotal);
        }

        $subtotal = array_reduce($groups, fn (Money $sum, array $group): Money => $sum->plus($group['subtotal']), $zero);
        $freeShipping = $subtotal->isGreaterThanOrEqualTo($this->money->of($setting('free_shipping_threshold'), $currency))
            || ($groups !== [] && app(Memberships::class)->hasFreeShipping($cart->customer_user_id));
        $zone = $groups === [] ? null : $this->zones->resolve($destination ?? $cart->destination());
        $wantedMethod = $cart->shipping_method ?? ShippingMethod::Standard;
        $flatFee = $this->money->of($setting('shipping_fee_per_seller'), $currency);

        foreach ($groups as $vendorId => $group) {
            $shipment = $this->shipments->quote($country, $zone, $vendorId, $group['listings'], $wantedMethod, $flatFee, $now);
            $subsidised = ! $freeShipping && $shipment->subsidyEligible;
            $vendorShare = $subsidised ? $this->money->percentageOf($shipment->cost, $shipment->vendorSubsidySharePercent) : $zero;
            unset($groups[$vendorId]['listings']);

            $groups[$vendorId] += [
                'shipping_method' => $shipment->method,
                'shipping_zone_id' => $shipment->zoneId,
                'shipping_cost' => $shipment->cost,
                'shipping_absorbed' => $freeShipping ? $shipment->cost : $zero,
                'shipping_subsidy' => $subsidised ? $shipment->cost : $zero,
                'shipping_subsidy_vendor' => $vendorShare,
                'shipping_subsidy_platform' => $subsidised ? $shipment->cost->minus($vendorShare) : $zero,
                'shipping_options' => $shipment->options,
                'eta' => $shipment->eta,
            ];
            $groups[$vendorId]['shipping'] = $freeShipping || $subsidised ? $zero : $shipment->cost;
        }

        [$groups, $appliedCoupons, $couponWarnings] = $this->applyCoupons($cart, $groups, $zero, $items->pluck('listing')->keyBy('id'));

        $discountTotal = $zero;
        $shippingTotal = $zero;
        $warrantyTotal = $zero;
        $subsidyTotal = $zero;
        $absorbedTotal = $zero;

        foreach ($groups as $vendorId => $group) {
            $groups[$vendorId]['total'] = $group['subtotal']->minus($group['discount'])->plus($group['shipping']);
            $discountTotal = $discountTotal->plus($group['discount']);
            $shippingTotal = $shippingTotal->plus($group['shipping']);
            $warrantyTotal = $warrantyTotal->plus($group['warranty']);
            $subsidyTotal = $subsidyTotal->plus($group['shipping_subsidy']);
            $absorbedTotal = $absorbedTotal->plus($group['shipping_absorbed']);
        }

        $codFee = $paymentMethod === 'cod' && $groups !== [] ? $this->money->of($setting('cod_fee'), $currency) : $zero;
        $grandTotal = $subtotal->minus($discountTotal)->plus($shippingTotal)->plus($codFee)->plus($warrantyTotal);
        $taxRate = (string) $country->tax_rate;
        $taxIncluded = $grandTotal->multipliedBy($taxRate, RoundingMode::HalfUp)->dividedBy(bcadd('100', $taxRate, 2), RoundingMode::HalfUp);

        return new CheckoutQuote(
            array_values($groups), $subtotal, $discountTotal, $shippingTotal, $codFee, $grandTotal, $taxIncluded, $taxRate,
            $appliedCoupons, [...$warnings, ...$couponWarnings], $warrantyTotal, $subsidyTotal, $absorbedTotal,
        );
    }

    /**
     * Seller coupons first (on that seller's items), then the Nawy coupon on
     * what is left (QC-014). Each coupon applies to its eligible lines only
     * (category / product / brand / seller targets) and is spread across
     * sellers in proportion to their eligible amounts — for BOGO, to the
     * sellers of the discounted units. Customers who are not eligible (first
     * order, segment) get a warning and no discount.
     *
     * @param  array<int, array<string, mixed>>  $groups
     * @param  Collection<int, Listing>  $listings  by id
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: array<int, string>}
     */
    private function applyCoupons(Cart $cart, array $groups, Money $zero, Collection $listings): array
    {
        $applied = [];
        $warnings = [];
        $currency = $zero->getCurrency()->getCurrencyCode();
        $coupons = $cart->coupons()->get()->sortBy(fn (Coupon $coupon): int => $coupon->scope === CouponScope::Vendor ? 0 : 1);

        foreach ($coupons as $coupon) {
            if (app(CouponValidator::class)->customerIneligibility($coupon, $cart->customer_user_id) !== null) {
                $warnings[] = __('Coupon :code is not available for your account and was not applied.', ['code' => $coupon->code]);

                continue;
            }

            $participants = $coupon->scope === CouponScope::Vendor ? null : app(CouponParticipations::class)->restrictedVendorIds($coupon);
            $targetVendorIds = match (true) {
                $coupon->scope === CouponScope::Vendor => array_values(array_filter([$coupon->vendor_id], fn (int $id): bool => isset($groups[$id]))),
                $participants !== null => array_values(array_intersect(array_keys($groups), $participants)),
                default => array_keys($groups),
            };

            $eligibleLines = [];

            foreach ($targetVendorIds as $id) {
                $lines = array_values(array_filter($groups[$id]['lines'], fn (array $line): bool => $listings->has($line['listing_id']) && $this->couponTargets->covers($coupon, $listings->get($line['listing_id']))));

                if ($lines !== []) {
                    $eligibleLines[$id] = $lines;
                }
            }

            $targetVendorIds = array_keys($eligibleLines);
            $orderBase = array_reduce($targetVendorIds, fn (Money $sum, int $id): Money => $sum->plus($groups[$id]['subtotal'])->minus($groups[$id]['discount']), $zero);

            if ($targetVendorIds === [] || ($coupon->min_order_amount !== null && $orderBase->isLessThan($this->money->of((string) $coupon->min_order_amount, $currency)))) {
                $warnings[] = __('Coupon :code needs a bigger order and was not applied.', ['code' => $coupon->code]);

                continue;
            }

            if ($coupon->type === CouponType::FreeShipping) {
                $saved = $zero;
                $shares = [];

                foreach ($targetVendorIds as $id) {
                    $saved = $saved->plus($groups[$id]['shipping']);
                    $shares[$id] = $groups[$id]['shipping'];
                    $groups[$id]['shipping'] = $zero;
                }

                $applied[] = ['coupon_id' => $coupon->id, 'code' => $coupon->code, 'scope' => $coupon->scope->value, 'type' => $coupon->type->value,
                    'discount' => $saved, 'free_shipping' => true, 'funded_by' => $coupon->funding()->value, 'shares' => $shares];

                continue;
            }

            /** @var array<int, Money> $weights eligible amount per seller (what the discount is spread over) */
            $weights = [];

            foreach ($eligibleLines as $id => $lines) {
                $lineTotal = array_reduce($lines, fn (Money $sum, array $line): Money => $sum->plus($line['line_total']), $zero);
                $weights[$id] = Money::min($lineTotal, Money::max($zero, $groups[$id]['subtotal']->minus($groups[$id]['discount'])));
            }

            $base = array_reduce($weights, fn (Money $sum, Money $weight): Money => $sum->plus($weight), $zero);

            if ($coupon->type === CouponType::Bogo) {
                $weights = $this->bogoFreeUnits($coupon, $eligibleLines, $weights, $zero);
                $freeValue = array_reduce($weights, fn (Money $sum, Money $weight): Money => $sum->plus($weight), $zero);
                $discount = $this->money->percentageOf($freeValue, (string) ($coupon->value > 0 ? $coupon->value : '100'));

                if ($discount->isZero()) {
                    $warnings[] = __('Add :count eligible items to use coupon :code.', ['count' => (int) $coupon->buy_quantity + (int) $coupon->get_quantity, 'code' => $coupon->code]);

                    continue;
                }
            } else {
                $discount = $coupon->type === CouponType::Percentage
                    ? $this->money->percentageOf($base, (string) $coupon->value)
                    : $this->money->of((string) $coupon->value, $currency);
            }

            if ($coupon->max_discount_amount !== null) {
                $discount = Money::min($discount, $this->money->of((string) $coupon->max_discount_amount, $currency));
            }

            $discount = Money::min($discount, $base);
            $weights = array_filter($weights, fn (Money $weight): bool => $weight->isPositive());
            $weightTotal = array_reduce($weights, fn (Money $sum, Money $weight): Money => $sum->plus($weight), $zero);

            if ($weights === [] || ! $discount->isPositive()) {
                continue;
            }

            $remaining = $discount;
            $shares = [];
            $lastId = array_key_last($weights);

            foreach ($weights as $id => $weight) {
                $share = $id === $lastId
                    ? $remaining
                    : $discount->multipliedBy($weight->getAmount(), RoundingMode::HalfUp)->dividedBy($weightTotal->getAmount(), RoundingMode::HalfUp);
                $groups[$id]['discount'] = $groups[$id]['discount']->plus($share);
                $shares[$id] = $share;
                $remaining = $remaining->minus($share);
            }

            $applied[] = ['coupon_id' => $coupon->id, 'code' => $coupon->code, 'scope' => $coupon->scope->value, 'type' => $coupon->type->value,
                'discount' => $discount, 'free_shipping' => false, 'funded_by' => $coupon->funding()->value, 'shares' => $shares];
        }

        return [$groups, $applied, $warnings];
    }

    /**
     * BOGO (QC-014): every eligible unit, dearest first; for each full set
     * of buy + get units, the `get` cheapest units of the cart are the free
     * ones. Returns the value of the free units per seller (capped at what
     * is left of the seller's goods after earlier coupons).
     *
     * @param  array<int, array<int, array<string, mixed>>>  $eligibleLines
     * @param  array<int, Money>  $caps
     * @return array<int, Money>
     */
    private function bogoFreeUnits(Coupon $coupon, array $eligibleLines, array $caps, Money $zero): array
    {
        $buy = max(1, (int) $coupon->buy_quantity);
        $get = max(1, (int) $coupon->get_quantity);
        $units = [];

        foreach ($eligibleLines as $vendorId => $lines) {
            foreach ($lines as $line) {
                for ($unit = 0; $unit < $line['quantity']; $unit++) {
                    $units[] = ['vendor_id' => $vendorId, 'price' => $line['unit_price']];
                }
            }
        }

        usort($units, fn (array $a, array $b): int => $b['price']->compareTo($a['price']));
        $freeCount = intdiv(count($units), $buy + $get) * $get;
        $free = array_fill_keys(array_keys($eligibleLines), $zero);

        foreach (array_slice($units, count($units) - $freeCount) as $unit) {
            $free[$unit['vendor_id']] = $free[$unit['vendor_id']]->plus($unit['price']);
        }

        foreach ($free as $vendorId => $value) {
            $free[$vendorId] = Money::min($value, $caps[$vendorId]);
        }

        return $free;
    }
}
