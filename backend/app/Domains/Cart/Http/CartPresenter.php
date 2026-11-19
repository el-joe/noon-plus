<?php

namespace App\Domains\Cart\Http;

use App\Domains\Cart\Models\Cart;
use App\Domains\Cart\Support\CheckoutQuote;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Settings\SettingsService;
use App\Shared\Money\MoneyFactory;
use Brick\Math\RoundingMode;
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
                    'product_slug' => $line['product_slug'] ?? null,
                    'image' => $line['image'] ?? null,
                    'quantity' => $line['quantity'],
                    'unit_price' => $money($line['unit_price']),
                    'regular_price' => $money($line['regular_unit_price']),
                    'line_total' => $money($line['line_total']),
                    'fulfilment_mode' => $line['fulfilment_mode'] ?? null,
                    'measurements' => $line['measurements'] ?? null,
                    'measurement_note' => $line['measurement_note'] ?? null,
                    'measurement_fields' => $line['measurement_fields'] ?? [],
                    'measurements_required' => ($line['measurement_fields'] ?? []) !== [],
                    'measurements_missing' => ($line['measurement_fields'] ?? []) !== [] && empty($line['measurements']),
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
            'free_delivery' => self::freeDelivery($quote),
            'warnings' => $quote->warnings,
            'hold_minutes' => (int) config('nawy.checkout.cart_lock_minutes'),
        ];
    }

    /**
     * G-A5: the free-delivery threshold (`checkout.free_shipping_threshold`)
     * and what is left to spend for the "spend X more" bar. The subtotal is
     * the items' value before coupons, as the calculator compares it.
     *
     * @return array{threshold: array{amount: string, currency: string}, remaining: array{amount: string, currency: string}, progress_percent: int, qualified: bool}|null
     */
    private static function freeDelivery(CheckoutQuote $quote): ?array
    {
        $country = app(CountryContext::class)->current();
        $default = config("nawy.checkout.defaults.{$country->code}.free_shipping_threshold");
        $value = app(SettingsService::class)->get('checkout.free_shipping_threshold', $default, $country);

        if ($value === null || $value === '') {
            return null;
        }

        $threshold = app(MoneyFactory::class)->of((string) $value, $country->currency);
        $subtotal = $quote->subtotal;
        $remaining = $subtotal->isGreaterThanOrEqualTo($threshold) ? $threshold->multipliedBy(0) : $threshold->minus($subtotal);
        $progress = $threshold->isZero() ? 100 : min(100, $subtotal->getAmount()->multipliedBy(100)->dividedBy($threshold->getAmount(), 0, RoundingMode::Down)->toInt());
        $money = fn (Money $value): array => ['amount' => (string) $value->getAmount(), 'currency' => $value->getCurrency()->getCurrencyCode()];

        return [
            'threshold' => $money($threshold),
            'remaining' => $money($remaining),
            'progress_percent' => $progress,
            'qualified' => $remaining->isZero() || ! $quote->shippingAbsorbedTotal->isZero(),
        ];
    }
}
