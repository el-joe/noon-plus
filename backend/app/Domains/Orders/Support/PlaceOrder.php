<?php

namespace App\Domains\Orders\Support;

use App\Domains\Campaigns\Support\Attribution;
use App\Domains\Cart\Exceptions\CartRejected;
use App\Domains\Cart\Models\Cart;
use App\Domains\Cart\Support\CheckoutCalculator;
use App\Domains\Cart\Support\StockAvailability;
use App\Domains\Catalog\Support\Measurements;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Coupons\Models\CouponRedemption;
use App\Domains\Finance\Support\CommissionRates;
use App\Domains\FlashSales\Enums\FlashSaleSubmissionStatus;
use App\Domains\FlashSales\Models\FlashSaleSubmission;
use App\Domains\FlashSales\Support\FlashSalePricing;
use App\Domains\FlashSales\Support\FlashSaleSubmissionMachine;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Support\PaymentMethodCatalog;
use App\Domains\Settings\SettingsService;
use App\Domains\Shipping\Support\ShippingDestination;
use App\Domains\Warranty\Enums\WarrantyPurchaseStatus;
use App\Domains\Warranty\Models\WarrantyPurchase;
use App\Shared\Money\MoneyFactory;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns a cart into one order with one sub-order per seller (FR-CHK-5);
 * a seller's FBN and seller-shipped items become two sub-orders (two
 * parcels, owner decision 2026-11-19, see {@see self::parcels()}).
 * Stock is re-checked under row locks and taken from the inventory ledger in
 * the same transaction, so two customers can never buy the same last unit.
 * Running flash deals are locked too, so their caps and per-customer
 * maximums hold under concurrency. Shipping is quoted to the order address;
 * the method, ETA, subsidy split, warranty plans and return window are
 * snapshotted with the category commission rate, and warranty purchases
 * are created. The payment method must
 * be offered for the order total ({@see PaymentMethodCatalog}); an unpaid
 * card/BNPL order is held until `payment_due_at`. Marketer conversions are
 * recorded for referred items (direct code, else the attribution model).
 */
class PlaceOrder
{
    public function __construct(
        private CountryContext $countryContext,
        private CheckoutCalculator $calculator,
        private StockAvailability $stockAvailability,
        private InventoryLedger $inventoryLedger,
        private MoneyFactory $money,
        private FlashSalePricing $flashPricing,
        private FlashSaleSubmissionMachine $flashSubmissions,
        private ReturnWindow $returnWindow,
        private PaymentMethodCatalog $paymentMethods,
        private SettingsService $settings,
        private CommissionRates $commissionRates,
    ) {}

    /**
     * @param  array<string, mixed>  $shippingAddress
     */
    public function handle(Cart $cart, int $customerUserId, string $paymentMethodCode, array $shippingAddress, ?string $visitorToken = null, ?string $referralCode = null): Order
    {
        $country = $this->countryContext->current();

        return DB::connection($cart->getConnectionName())->transaction(function () use ($cart, $customerUserId, $paymentMethodCode, $shippingAddress, $country, $visitorToken, $referralCode): Order {
            $items = $cart->items()->orderBy('listing_id')->get();

            if ($items->isEmpty()) {
                throw new CartRejected('cart_empty', __('Your cart is empty.'));
            }

            $listings = Listing::query()->whereIn('id', $items->pluck('listing_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($items as $item) {
                $listing = $listings->get($item->listing_id);

                if ($listing === null || ! $listing->status->isSellable() || $this->stockAvailability->availableFor($listing, $cart->id) < $item->quantity) {
                    throw new CartRejected('insufficient_stock', __('Some items are no longer available in the quantity you chose.'), 409);
                }

                if (empty($item->measurements) && app(Measurements::class)->requiredFor($listing->variant->product)) {
                    throw new CartRejected('measurements_required', __('Add your measurements for :title (made to order).', ['title' => $listing->variant->product->title()]), 409);
                }
            }

            $deals = $this->flashPricing->activeDeals($listings->keys()->all(), lockForUpdate: true);
            $quote = $this->calculator->quote($cart, $paymentMethodCode === PaymentMethod::Cod->value ? 'cod' : null, ShippingDestination::fromAddress($shippingAddress));
            $paymentMethod = $this->paymentMethods->find($country, $paymentMethodCode, $quote->grandTotal, $customerUserId)['method']
                ?? throw new CartRejected('payment_method_unavailable', __('This payment method is not available for this order.'), 422);

            if ($quote->exceedsFlashLimits()) {
                throw new CartRejected('flash_sale_limit', __('A deal in your cart is over its limit. Lower the quantity to keep the deal price.'), 409);
            }

            $amount = fn (Money $money): string => $this->money->toStorage($money);
            $categoryIds = array_merge(...array_map(fn (array $group): array => array_column($group['lines'], 'category_id'), $quote->groups));
            $returnWindows = $this->returnWindow->daysForCategories($country, $categoryIds);
            $commissionRates = $this->commissionRates->forCategories($categoryIds);

            $order = Order::query()->create([
                'number' => 'PENDING-'.uniqid(),
                'customer_user_id' => $customerUserId,
                'status' => OrderStatus::Placed,
                'payment_method' => $paymentMethod->value,
                'payment_status' => 'pending',
                'payment_due_at' => $paymentMethod === PaymentMethod::Cod ? null : now()->addMinutes($this->pendingExpiryMinutes()),
                'currency' => $country->currency,
                'subtotal' => $amount($quote->subtotal),
                'discount_total' => $amount($quote->discountTotal),
                'shipping_total' => $amount($quote->shippingTotal),
                'shipping_subsidy_total' => $amount($quote->shippingSubsidyTotal),
                'cod_fee' => $amount($quote->codFee),
                'warranty_total' => $amount($quote->warrantyTotal),
                'grand_total' => $amount($quote->grandTotal),
                'tax_included' => $amount($quote->taxIncluded),
                'tax_rate' => $quote->taxRate,
                'shipping_address' => $shippingAddress,
                'placed_at' => now(),
            ]);
            $order->forceFill(['number' => sprintf('NW-%s-%07d', $country->iso2, 1_000_000 + $order->id)])->save();

            $sequence = 0;
            $parcels = [];

            foreach ($quote->groups as $quoteGroup) {
                foreach ($this->parcels($quoteGroup, $listings) as $parcel) {
                    $parcels[] = $parcel;
                }
            }

            $splitFrom = [];

            foreach ($parcels as $group) {
                $subOrder = $order->subOrders()->create([
                    'vendor_id' => $group['vendor_id'],
                    'fulfilled_by' => $group['fulfilled_by'],
                    'split_from_sub_order_id' => $group['fulfilled_by'] === 'nawy' ? ($splitFrom[$group['vendor_id']] ?? null) : null,
                    'number' => $order->number.'-'.(++$sequence),
                    'subtotal' => $amount($group['subtotal']),
                    'discount_total' => $amount($group['discount']),
                    'shipping_total' => $amount($group['shipping']),
                    'shipping_method' => $group['shipping_method']->value,
                    'shipping_zone_id' => $group['shipping_zone_id'],
                    'shipping_cost' => $amount($group['shipping_cost']),
                    'shipping_subsidy_vendor' => $amount($group['shipping_subsidy_vendor']),
                    'shipping_subsidy_platform' => $amount($group['shipping_subsidy_platform']),
                    'shipping_absorbed' => $amount($group['shipping_absorbed']),
                    'warranty_total' => $amount($group['warranty']),
                    'total' => $amount($group['total']),
                    'estimated_delivery_from' => $group['eta']->from->toDateString(),
                    'estimated_delivery_by' => $group['eta']->by->toDateString(),
                ]);
                if ($group['fulfilled_by'] === 'seller' && $group['split']) {
                    $splitFrom[$group['vendor_id']] = $subOrder->id;
                }

                $subOrder->statusHistory()->create([
                    'to_status' => SubOrderStatus::Placed,
                    'actor_type' => ActorType::Customer,
                    'actor_user_id' => $customerUserId,
                ]);

                foreach ($group['lines'] as $line) {
                    $orderItem = $subOrder->items()->create([
                        'listing_id' => $line['listing_id'],
                        'sku' => $line['sku'],
                        'title' => $line['title'],
                        'quantity' => $line['quantity'],
                        'unit_price' => $amount($line['unit_price']),
                        'regular_unit_price' => $amount($line['regular_unit_price']),
                        'line_total' => $amount($line['line_total']),
                        'flash_sale_submission_id' => $line['flash_sale_submission_id'],
                        'warranty_plan_id' => $line['warranty_plan_id'],
                        'warranty_unit_price' => $amount($line['warranty_unit_price']),
                        'warranty_total' => $amount($line['warranty_total']),
                        'return_window_days' => $returnWindows[$line['category_id']],
                        'commission_rate' => $commissionRates[$line['category_id']],
                        'measurements' => $line['measurements'] ?? null,
                        'measurement_note' => $line['measurement_note'] ?? null,
                    ]);
                    $this->inventoryLedger->move($listings->get($line['listing_id']), -$line['quantity'], 'sale', 'order_item', $orderItem->id, $customerUserId);

                    if ($line['flash_sale_submission_id'] !== null) {
                        $this->takeFlashUnits($deals->get($line['listing_id']), $line['quantity']);
                    }

                    if ($line['warranty_plan_id'] !== null) {
                        WarrantyPurchase::query()->create([
                            'order_id' => $order->id,
                            'order_item_id' => $orderItem->id,
                            'warranty_plan_id' => $line['warranty_plan_id'],
                            'customer_user_id' => $customerUserId,
                            'status' => WarrantyPurchaseStatus::Active,
                            'quantity' => $line['quantity'],
                            'unit_price' => $amount($line['warranty_unit_price']),
                            'total' => $amount($line['warranty_total']),
                            'duration_months' => $line['warranty_months'],
                            'starts_at' => now(),
                            'expires_at' => now()->addMonthsNoOverflow($line['warranty_months']),
                        ]);
                    }
                }
            }

            foreach ($quote->coupons as $coupon) {
                CouponRedemption::query()->create([
                    'coupon_id' => $coupon['coupon_id'],
                    'order_id' => $order->id,
                    'customer_user_id' => $customerUserId,
                    'discount_amount' => $amount($coupon['discount']),
                    'funded_by' => $coupon['funded_by'] ?? null,
                    'vendor_shares' => array_map(fn ($share): string => $amount($share), $coupon['shares'] ?? []),
                ]);
            }

            $cart->items()->delete();
            $cart->locks()->delete();
            $cart->coupons()->detach();

            app(Attribution::class)->attributeOrder($order, $visitorToken, $referralCode);

            return $order->load('subOrders.items');
        });
    }

    /**
     * The parcels of one seller's checkout group. Owner decision 2026-11-19:
     * a group mixing FBN items and items the seller ships becomes two
     * sub-orders. The seller's parcel comes first and keeps the shipping
     * charged (the customer pays the same as one parcel); the warehouse
     * parcel (`fulfilled_by` = `nawy`) carries the FBN lines, their warranty
     * and a share of the seller's discount pro rata to its goods.
     *
     * @param  array<string, mixed>  $group
     * @param  Collection<int, Listing>  $listings
     * @return list<array<string, mixed>>
     */
    private function parcels(array $group, Collection $listings): array
    {
        $isFbn = fn (array $line): bool => $listings->get($line['listing_id'])?->fulfilment === 'fbn';
        $fbnLines = array_values(array_filter($group['lines'], $isFbn));
        $sellerLines = array_values(array_filter($group['lines'], fn (array $line): bool => ! $isFbn($line)));

        if ($fbnLines === [] || $sellerLines === []) {
            return [$group + ['fulfilled_by' => $sellerLines === [] && $fbnLines !== [] ? 'nawy' : 'seller', 'split' => false]];
        }

        /** @var Money $zero */
        $zero = $group['subtotal']->multipliedBy(0);
        $subtotal = array_reduce($fbnLines, fn (Money $sum, array $line): Money => $sum->plus($line['line_total']), $zero);
        $warranty = array_reduce($fbnLines, fn (Money $sum, array $line): Money => $sum->plus($line['warranty_total']), $zero);
        $discount = $group['subtotal']->isPositive()
            ? Money::min($subtotal, $group['discount']->multipliedBy(BigDecimal::of((string) $subtotal->getAmount())->toBigRational()->dividedBy((string) $group['subtotal']->getAmount()), RoundingMode::Down))
            : $zero;
        $total = $subtotal->minus($discount);

        $warehouse = array_merge($group, [
            'lines' => $fbnLines,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'shipping' => $zero,
            'shipping_cost' => $zero,
            'shipping_subsidy_vendor' => $zero,
            'shipping_subsidy_platform' => $zero,
            'shipping_absorbed' => $zero,
            'warranty' => $warranty,
            'total' => $total,
            'fulfilled_by' => 'nawy',
            'split' => true,
        ]);
        $seller = array_merge($group, [
            'lines' => $sellerLines,
            'subtotal' => $group['subtotal']->minus($subtotal),
            'discount' => $group['discount']->minus($discount),
            'warranty' => $group['warranty']->minus($warranty),
            'total' => $group['total']->minus($total),
            'fulfilled_by' => 'seller',
            'split' => true,
        ]);

        return [$seller, $warehouse];
    }

    /**
     * Count units sold at the flash price; a live deal that reaches its cap
     * is sold out.
     */
    private function takeFlashUnits(FlashSaleSubmission $deal, int $quantity): void
    {
        $deal->increment('sold_quantity', $quantity);

        if ($deal->remainingQuantity() === 0 && $deal->status === FlashSaleSubmissionStatus::Live) {
            $this->flashSubmissions->transition($deal, FlashSaleSubmissionStatus::SoldOut, ActorType::System);
        }
    }

    /**
     * Hold window of an unpaid card/BNPL order (setting `payment.pending_expiry_minutes`).
     */
    private function pendingExpiryMinutes(): int
    {
        return (int) $this->settings->get('payment.pending_expiry_minutes', config('nawy.payments.pending_expiry_minutes'));
    }
}
