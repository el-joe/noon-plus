@php($currency = $country->currency)
{{ match ($coupon->type) {
    \App\Domains\Coupons\Enums\CouponType::Percentage => rtrim(rtrim((string) $coupon->value, '0'), '.').'% '.__('off'),
    \App\Domains\Coupons\Enums\CouponType::FixedAmount => app(\App\Shared\Money\MoneyFormatter::class)->format((string) $coupon->value, $currency).' '.__('off'),
    \App\Domains\Coupons\Enums\CouponType::FreeShipping => __('Free shipping'),
    \App\Domains\Coupons\Enums\CouponType::Bogo => __('Buy :buy get :get', ['buy' => $coupon->buy_quantity, 'get' => $coupon->get_quantity]).((float) $coupon->value < 100 ? ' ('.rtrim(rtrim((string) $coupon->value, '0'), '.').'% '.__('off').')' : ' '.__('free')),
} }}
