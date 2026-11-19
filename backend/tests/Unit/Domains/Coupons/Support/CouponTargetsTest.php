<?php

use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductVariant;
use App\Domains\Coupons\Enums\CouponTarget;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Coupons\Support\CouponTargets;
use App\Domains\Listings\Models\Listing;
use Illuminate\Foundation\Testing\TestCase;

/*
| Category targets read the category tree from the database and are covered
| by the feature tests; the other targets match ids in memory.
*/

uses(TestCase::class);

function couponTargetListing(int $vendorId = 10, int $productId = 20, ?int $brandId = 30): Listing
{
    $product = (new Product)->forceFill(['id' => $productId, 'brand_id' => $brandId, 'category_id' => 40]);
    $variant = (new ProductVariant)->setRelation('product', $product);

    return (new Listing)->forceFill(['vendor_id' => $vendorId])->setRelation('variant', $variant);
}

it('covers a listing when its seller, product or brand is targeted', function (CouponTarget $target, array $ids, bool $expected) {
    $coupon = new Coupon(['target_type' => $target, 'target_ids' => $ids]);

    expect((new CouponTargets)->covers($coupon, couponTargetListing()))->toBe($expected);
})->with([
    'all items' => [CouponTarget::All, [], true],
    'targeted seller' => [CouponTarget::Vendor, [10, 11], true],
    'another seller' => [CouponTarget::Vendor, [11], false],
    'targeted product given as string id' => [CouponTarget::Product, ['20'], true],
    'another product' => [CouponTarget::Product, [21], false],
    'targeted brand' => [CouponTarget::Brand, [30], true],
    'another brand' => [CouponTarget::Brand, [31], false],
    'no ids targeted' => [CouponTarget::Vendor, [], false],
]);

it('does not cover a product without a brand for a brand coupon', function () {
    $coupon = new Coupon(['target_type' => CouponTarget::Brand, 'target_ids' => [0]]);

    expect((new CouponTargets)->covers($coupon, couponTargetListing(brandId: null)))->toBeFalse();
});
