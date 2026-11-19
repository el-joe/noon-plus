<?php

namespace App\Domains\Coupons\Support;

use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Coupons\Enums\CouponScope;
use App\Domains\Coupons\Enums\CouponTarget;
use App\Domains\Coupons\Enums\CouponType;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Settings\VerticalSettings;
use Illuminate\Database\Eloquent\Builder;

/**
 * Seller Center coupons (SRS §10, QC-014): the store's own vendor-funded
 * coupons. They apply only to the store's items (CheckoutCalculator), may
 * narrow to the store's own products, categories or brands, and the
 * discount comes off the store's settlement (`vendor_shares`, owner F3).
 * Free shipping is not offered to sellers: owner decision F3 makes Nawy pay
 * for free-shipping coupons. Limits are country settings `coupons.*`.
 */
class SellerCoupons
{
    /**
     * Types a seller may create.
     *
     * @var array<int, CouponType>
     */
    public const TYPES = [CouponType::Percentage, CouponType::FixedAmount, CouponType::Bogo];

    /**
     * Targets a seller may choose (always within the store's own items).
     *
     * @var array<int, CouponTarget>
     */
    public const TARGETS = [CouponTarget::All, CouponTarget::Product, CouponTarget::Category, CouponTarget::Brand];

    public function __construct(private VerticalSettings $settings) {}

    /**
     * @return Builder<Coupon>
     */
    public function query(Vendor $vendor): Builder
    {
        return Coupon::query()->where('scope', CouponScope::Vendor)->where('vendor_id', $vendor->id);
    }

    /**
     * The store's coupons that are switched on and not yet ended (scheduled ones count).
     */
    public function liveCount(Vendor $vendor, ?int $exceptCouponId = null): int
    {
        return $this->query($vendor)->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->when($exceptCouponId !== null, fn ($query) => $query->whereKeyNot($exceptCouponId))
            ->count();
    }

    public function maxLive(): int
    {
        return max(1, $this->settings->int('coupons.seller_max_live'));
    }

    public function maxPercent(): int
    {
        return min(100, max(1, $this->settings->int('coupons.seller_max_percent')));
    }

    public function maxDays(): int
    {
        return max(1, $this->settings->int('coupons.seller_max_days'));
    }

    /**
     * @return Builder<Product>
     */
    public function products(Vendor $vendor): Builder
    {
        return Product::query()->whereHas('variants.listings', fn ($listing) => $listing->where('vendor_id', $vendor->id));
    }

    /**
     * @return array<int, string> id => title
     */
    public function productOptions(Vendor $vendor): array
    {
        return $this->products($vendor)->latest('id')->limit(500)->get(['id', 'title_en', 'title_ar'])
            ->mapWithKeys(fn (Product $product): array => [$product->id => $product->title().' (#'.$product->id.')'])->all();
    }

    /**
     * @return array<int, string> id => name
     */
    public function categoryOptions(Vendor $vendor): array
    {
        return Category::query()->whereIn('id', $this->products($vendor)->select('category_id'))->orderBy('name_en')
            ->get()->mapWithKeys(fn (Category $category): array => [$category->id => $category->name()])->all();
    }

    /**
     * @return array<int, string> id => name
     */
    public function brandOptions(Vendor $vendor): array
    {
        return Brand::query()->whereIn('id', $this->products($vendor)->whereNotNull('brand_id')->select('brand_id'))->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Names of the coupon's targets, for the detail page.
     *
     * @return array<int, string>
     */
    public function targetNames(Coupon $coupon): array
    {
        $ids = $coupon->targetIds();

        return match ($coupon->target_type) {
            CouponTarget::Category => Category::query()->whereIn('id', $ids)->pluck('name_en')->all(),
            CouponTarget::Brand => Brand::query()->whereIn('id', $ids)->pluck('name')->all(),
            CouponTarget::Product => Product::query()->whereIn('id', $ids)->pluck('title_en')->all(),
            default => [],
        };
    }
}
