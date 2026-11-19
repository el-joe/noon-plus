<?php

use App\Domains\Coupons\Enums\CouponFunding;
use App\Domains\Coupons\Enums\CouponScope;
use App\Domains\Coupons\Enums\CustomerSegment;
use App\Domains\Coupons\Models\Coupon;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class);

it('is funded by the seller for seller coupons, whatever is stored', function () {
    $coupon = new Coupon(['scope' => CouponScope::Vendor, 'funded_by' => CouponFunding::Platform]);

    expect($coupon->funding())->toBe(CouponFunding::Vendor);
});

it('is funded as stored for Nawy coupons, the platform by default', function (?CouponFunding $stored, CouponFunding $expected) {
    $coupon = new Coupon(['scope' => CouponScope::Platform]);
    $coupon->funded_by = $stored;

    expect($coupon->funding())->toBe($expected);
})->with([
    'vendor-funded Nawy coupon' => [CouponFunding::Vendor, CouponFunding::Vendor],
    'platform' => [CouponFunding::Platform, CouponFunding::Platform],
    'nothing stored' => [null, CouponFunding::Platform],
]);

it('needs to know the customer only for first-order or segment coupons', function (bool $firstOrderOnly, ?CustomerSegment $segment, bool $expected) {
    $coupon = new Coupon(['first_order_only' => $firstOrderOnly]);
    $coupon->customer_segment = $segment;

    expect($coupon->needsCustomer())->toBe($expected);
})->with([
    'everyone' => [false, CustomerSegment::Everyone, false],
    'no segment stored' => [false, null, false],
    'first order only' => [true, CustomerSegment::Everyone, true],
    'new customers' => [false, CustomerSegment::NewCustomers, true],
    'returning customers' => [false, CustomerSegment::ReturningCustomers, true],
    'Nawy One members' => [false, CustomerSegment::NawyOneMembers, true],
]);

it('reads target ids as a list of integers', function (?array $stored, array $expected) {
    $coupon = new Coupon(['target_ids' => $stored]);

    expect($coupon->targetIds())->toBe($expected);
})->with([
    'strings from a form' => [['3' => '7', '5' => '12'], [7, 12]],
    'integers' => [[1, 2], [1, 2]],
    'nothing stored' => [null, []],
]);
