<?php

use App\Domains\Coupons\Enums\CouponType;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Listings\Models\Listing;
use App\Domains\Vendors\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->actAsCountry('uae');
});

/**
 * Adds items as a guest and returns the guest token.
 *
 * @param  array<int, array{0: Listing, 1: int}>  $lines
 */
function guestCartWith(array $lines): string
{
    $token = null;

    foreach ($lines as [$listing, $quantity]) {
        $response = test()->withHeader('X-Cart-Token', (string) $token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => $quantity]);
        $token = $response->headers->get('X-Cart-Token');
    }

    return $token;
}

it('charges shipping per seller below the free-shipping threshold and shows the VAT included', function () {
    $token = guestCartWith([[sellableListing('30.00'), 1], [sellableListing('20.00'), 1]]);

    $this->withHeader('X-Cart-Token', $token)->getJson('/api/v1/uae-en/cart')
        ->assertJsonCount(2, 'data.shipments')
        ->assertJsonPath('data.totals.subtotal.amount', '50.00')
        ->assertJsonPath('data.totals.shipping.amount', '20.00')
        ->assertJsonPath('data.totals.total.amount', '70.00')
        ->assertJsonPath('data.totals.tax_included.amount', '3.33');
});

it('ships free once the order reaches the threshold and adds the COD fee for cash on delivery', function () {
    $token = guestCartWith([[sellableListing('100.00'), 1]]);

    $this->withHeader('X-Cart-Token', $token)->getJson('/api/v1/uae-en/cart?payment_method=cod')
        ->assertJsonPath('data.totals.shipping.amount', '0.00')
        ->assertJsonPath('data.totals.cod_fee.amount', '10.00')
        ->assertJsonPath('data.totals.total.amount', '110.00');
});

it('applies one Nawy coupon and one seller coupon, seller coupon on that seller only', function () {
    $soundmax = Vendor::factory()->create();
    $token = guestCartWith([[sellableListing('200.00', vendor: $soundmax), 1], [sellableListing('100.00'), 1]]);
    Coupon::factory()->forVendor($soundmax)->create(['code' => 'SOUND20', 'type' => CouponType::FixedAmount, 'value' => '20']);
    Coupon::factory()->create(['code' => 'NAWY10', 'type' => CouponType::Percentage, 'value' => '10']);

    $this->withHeader('X-Cart-Token', $token)->postJson('/api/v1/uae-en/cart/coupons', ['code' => 'sound20'])->assertOk();
    $this->withHeader('X-Cart-Token', $token)->postJson('/api/v1/uae-en/cart/coupons', ['code' => 'NAWY10'])
        ->assertOk()
        ->assertJsonPath('data.coupons.0.code', 'SOUND20')
        ->assertJsonPath('data.coupons.1.discount.amount', '28.00')
        ->assertJsonPath('data.totals.discount.amount', '48.00')
        ->assertJsonPath('data.shipments.0.discount.amount', '38.00')
        ->assertJsonPath('data.shipments.1.discount.amount', '10.00');
});

it('refuses a second Nawy coupon with 422', function () {
    $token = guestCartWith([[sellableListing('100.00'), 1]]);
    Coupon::factory()->create(['code' => 'FIRST']);
    Coupon::factory()->create(['code' => 'SECOND']);

    $this->withHeader('X-Cart-Token', $token)->postJson('/api/v1/uae-en/cart/coupons', ['code' => 'FIRST'])->assertOk();
    $this->withHeader('X-Cart-Token', $token)->postJson('/api/v1/uae-en/cart/coupons', ['code' => 'SECOND'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'coupon_limit');
});

it('refuses expired, used-up and other-seller coupons', function (string $code, string $errorCode) {
    $token = guestCartWith([[sellableListing('100.00'), 1]]);
    Coupon::factory()->create(['code' => 'OLD', 'ends_at' => now()->subDay()]);
    Coupon::factory()->create(['code' => 'GONE', 'usage_limit' => 0]);
    Coupon::factory()->forVendor(Vendor::factory()->create())->create(['code' => 'OTHERSHOP']);

    $this->withHeader('X-Cart-Token', $token)->postJson('/api/v1/uae-en/cart/coupons', ['code' => $code])
        ->assertStatus(422)
        ->assertJsonPath('error.code', $errorCode);
})->with([
    ['OLD', 'coupon_inactive'],
    ['GONE', 'coupon_used_up'],
    ['OTHERSHOP', 'coupon_seller_not_in_cart'],
    ['NOPE', 'coupon_not_found'],
]);

it('caps a percentage coupon at its maximum discount and skips it below the minimum order', function () {
    $token = guestCartWith([[sellableListing('400.00'), 1]]);
    Coupon::factory()->create(['code' => 'BIG', 'value' => '50', 'max_discount_amount' => '50']);

    $this->withHeader('X-Cart-Token', $token)->postJson('/api/v1/uae-en/cart/coupons', ['code' => 'BIG'])
        ->assertJsonPath('data.totals.discount.amount', '50.00');

    Coupon::query()->where('code', 'BIG')->update(['min_order_amount' => '500']);
    $this->withHeader('X-Cart-Token', $token)->getJson('/api/v1/uae-en/cart')
        ->assertJsonPath('data.totals.discount.amount', '0.00')
        ->assertJsonCount(1, 'data.warnings');
});

it('makes shipping free with a free-shipping coupon', function () {
    $token = guestCartWith([[sellableListing('30.00'), 1]]);
    Coupon::factory()->create(['code' => 'SHIPFREE', 'type' => CouponType::FreeShipping]);

    $this->withHeader('X-Cart-Token', $token)->postJson('/api/v1/uae-en/cart/coupons', ['code' => 'SHIPFREE'])
        ->assertJsonPath('data.totals.shipping.amount', '0.00')
        ->assertJsonPath('data.totals.total.amount', '30.00');
});

it('rounds Omani totals to 3 decimals', function () {
    $this->actAsCountry('omn');
    $listing = sellableListing('4.255');

    $token = $this->putJson('/api/v1/omn-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 2])->headers->get('X-Cart-Token');

    $this->withHeader('X-Cart-Token', $token)->getJson('/api/v1/omn-en/cart')
        ->assertJsonPath('data.totals.subtotal', ['amount' => '8.510', 'currency' => 'OMR'])
        ->assertJsonPath('data.totals.total.amount', '9.510');
});
