<?php

namespace App\Domains\Coupons\Support;

use App\Domains\Catalog\Models\Category;
use App\Domains\Coupons\Enums\CouponTarget;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Listings\Models\Listing;

/**
 * Whether a listing is covered by a coupon's target (QC-014): categories
 * include their sub-categories; products, brands and sellers match by id.
 */
class CouponTargets
{
    /**
     * @var array<int, array<int, int>>
     */
    private array $categoryTrees = [];

    public function covers(Coupon $coupon, Listing $listing): bool
    {
        $ids = $coupon->targetIds();
        $product = $listing->variant->product;

        return match ($coupon->target_type ?? CouponTarget::All) {
            CouponTarget::All => true,
            CouponTarget::Vendor => in_array((int) $listing->vendor_id, $ids, true),
            CouponTarget::Product => in_array((int) $product->id, $ids, true),
            CouponTarget::Brand => $product->brand_id !== null && in_array((int) $product->brand_id, $ids, true),
            CouponTarget::Category => in_array((int) $product->category_id, $this->categoryTree($coupon), true),
        };
    }

    /**
     * The coupon's categories and every descendant.
     *
     * @return array<int, int>
     */
    private function categoryTree(Coupon $coupon): array
    {
        if (isset($this->categoryTrees[$coupon->id])) {
            return $this->categoryTrees[$coupon->id];
        }

        $all = $coupon->targetIds();
        $frontier = $all;

        for ($depth = 0; $frontier !== [] && $depth < 10; $depth++) {
            $frontier = Category::query()->whereIn('parent_id', $frontier)->pluck('id')->map(fn ($id): int => (int) $id)->diff($all)->values()->all();
            $all = [...$all, ...$frontier];
        }

        return $this->categoryTrees[$coupon->id] = $all;
    }
}
