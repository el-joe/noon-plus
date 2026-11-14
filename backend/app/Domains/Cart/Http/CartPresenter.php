<?php

namespace App\Domains\Cart\Http;

use App\Domains\Cart\Models\Cart;
use App\Domains\Cart\Support\CheckoutQuote;
use Brick\Money\Money;

/**
 * JSON shape of a cart with its live totals, one shipment per seller with
 * its delivery method, options and "Get it by" date.
 */
class CartPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(Cart $cart, CheckoutQuote $quote): array
    {
        $money = fn (Money $value): array => ['amount' => (string) $value->getAmount(), 'currency' => $value->getCurrency()->getCurrencyCode()];

        return [
            'destination' => ['city' => $cart->destination_city, 'area' => $cart->destination_area],
            'shipments' => array_map(fn (array $group): array => [
                'seller' => $group['vendor_name'],
                'items' => array_map(fn (array $line): array => [
                    'listing_id' => $line['listing_id'],
                    'sku' => $line['sku'],
                    'title' => $line['title'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $money($line['unit_price']),
                    'regular_price' => $money($line['regular_unit_price']),
                    'line_total' => $money($line['line_total']),
                    'flash_sale' => $line['flash_sale_submission_id'] === null ? null : ['ends_at' => $line['flash_sale_ends_at']->toIso8601String()],
                    'warranty' => $line['warranty_plan_id'] === null ? null : [
                        'plan_id' => $line['warranty_plan_id'],
                        'name' => $line['warranty_name'],
                        'months' => $line['warranty_months'],
                        'unit_price' => $money($line['warranty_unit_price']),
                        'total' => $money($line['warranty_total']),
                    ],
                    'warranty_options' => array_map(fn (array $option): array => [
                        'plan_id' => $option['id'],
                        'name' => $option['name'],
                        'months' => $option['months'],
                        'price' => $money($option['price']),
                    ], $line['warranty_options']),
                ], $group['lines']),
                'subtotal' => $money($group['subtotal']),
                'discount' => $money($group['discount']),
                'shipping' => $money($group['shipping']),
                'shipping_cost' => $money($group['shipping_cost']),
                'shipping_subsidy' => $money($group['shipping_subsidy']),
                'free_shipping_threshold_applied' => ! $group['shipping_absorbed']->isZero(),
                'method' => $group['shipping_method']->value,
                'delivery' => $group['eta']->toArray(),
                'shipping_options' => array_map(fn (array $option): array => [
                    'method' => $option['method']->value,
                    'fee' => $money($option['fee']),
                    'delivery' => $option['eta']->toArray(),
                ], $group['shipping_options']),
                'warranty' => $money($group['warranty']),
                'total' => $money($group['total']),
            ], $quote->groups),
            'coupons' => array_map(fn (array $coupon): array => [
                'code' => $coupon['code'],
                'scope' => $coupon['scope'],
                'discount' => $money($coupon['discount']),
                'free_shipping' => $coupon['free_shipping'],
            ], $quote->coupons),
            'totals' => [
                'subtotal' => $money($quote->subtotal),
                'discount' => $money($quote->discountTotal),
                'shipping' => $money($quote->shippingTotal),
                'shipping_subsidy' => $money($quote->shippingSubsidyTotal),
                'cod_fee' => $money($quote->codFee),
                'warranty' => $money($quote->warrantyTotal),
                'total' => $money($quote->grandTotal),
                'tax_included' => $money($quote->taxIncluded),
                'tax_rate' => $quote->taxRate,
            ],
            'warnings' => $quote->warnings,
            'hold_minutes' => (int) config('nawy.checkout.cart_lock_minutes'),
        ];
    }
}
