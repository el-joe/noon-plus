<?php

use App\Domains\Cart\Exceptions\CartRejected;
use App\Domains\Cart\Models\Cart;
use App\Domains\Coupons\Enums\CustomerSegment;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Coupons\Support\CouponValidator;
use Illuminate\Foundation\Testing\TestCase;

/*
| The checks decided before any redemption, cart or order is read. Usage
| limits, seller / target matching and first-order or segment eligibility
| query the database and are covered by the feature tests.
*/

uses(TestCase::class);

beforeEach(fn () => $this->freezeTime());

it('rejects a coupon that is switched off or outside its dates', function (array $attributes) {
    // An unsaved model takes its date format from the connection otherwise.
    $coupon = (new Coupon)->setDateFormat('Y-m-d H:i:s')->fill($attributes);

    expect(fn () => app(CouponValidator::class)->assertApplicable($coupon, new Cart, 1))
        ->toThrow(function (CartRejected $e) {
            expect($e->errorCode)->toBe('coupon_inactive')
                ->and($e->getMessage())->toBe('This coupon is not active.')
                ->and($e->status)->toBe(422);
        });
})->with([
    'inactive' => [['is_active' => false]],
    'starts in the future' => [['is_active' => true, 'starts_at' => now()->addMinute()]],
    'ended' => [['is_active' => true, 'ends_at' => now()->subMinute()]],
]);

it('lets anyone use a coupon that does not depend on the customer', function (?int $customerUserId) {
    $coupon = new Coupon(['customer_segment' => CustomerSegment::Everyone, 'first_order_only' => false]);

    expect(app(CouponValidator::class)->customerIneligibility($coupon, $customerUserId))->toBeNull();
})->with([
    'guest' => [null],
    'customer' => [7],
]);

it('asks a guest to sign in for a first-order or segment coupon', function (array $attributes) {
    $coupon = new Coupon($attributes);

    expect(app(CouponValidator::class)->customerIneligibility($coupon, null))
        ->toBe(['coupon_sign_in_required', 'Sign in to use this coupon.']);
})->with([
    'first order only' => [['first_order_only' => true]],
    'Nawy One members' => [['customer_segment' => CustomerSegment::NawyOneMembers]],
]);

it('throws the ineligibility reason when asserting a guest may use a segment coupon', function () {
    $coupon = new Coupon(['customer_segment' => CustomerSegment::NewCustomers]);

    app(CouponValidator::class)->assertCustomerEligible($coupon, null);
})->throws(CartRejected::class, 'Sign in to use this coupon.');
