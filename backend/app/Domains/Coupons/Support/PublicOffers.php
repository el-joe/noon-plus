<?php

namespace App\Domains\Coupons\Support;

use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Coupons\Enums\CouponScope;
use App\Domains\Coupons\Enums\CouponTarget;
use App\Domains\Coupons\Enums\CouponType;
use App\Domains\Coupons\Enums\CustomerSegment;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Coupons\Models\CouponRedemption;
use App\Domains\Listings\Models\Listing;
use App\Domains\Vendors\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Coupons a shopper may see (QC-064 "Offers for you", QC-057 PDP coupon
 * chips). Only coupons an admin marked public (`is_public`) are ever
 * listed — never private, one-use or used-up codes. A signed-in customer
 * sees the ones they are eligible for (first order, segment, own uses);
 * a guest sees the open ones, and first-order / new-customer ones marked
 * `requires_sign_in`.
 */
class PublicOffers
{
    public function __construct(private CouponValidator $validator, private CouponTargets $targets) {}

    /**
     * @return Collection<int, Coupon>
     */
    public function forShopper(?int $customerUserId): Collection
    {
        $coupons = $this->candidates()->get();
        $ownUses = $customerUserId === null ? collect() : CouponRedemption::query()->where('customer_user_id', $customerUserId)
            ->whereIn('coupon_id', $coupons->modelKeys())->selectRaw('coupon_id, count(*) as total')->groupBy('coupon_id')->pluck('total', 'coupon_id');

        return $coupons->filter(function (Coupon $coupon) use ($customerUserId, $ownUses): bool {
            if ($coupon->usage_limit !== null && (int) $coupon->redemptions_count >= $coupon->usage_limit) {
                return false;
            }

            if ($customerUserId === null) {
                return ! $coupon->needsCustomer() || $this->guestTeaser($coupon);
            }

            if ($coupon->per_customer_limit !== null && (int) ($ownUses[$coupon->id] ?? 0) >= $coupon->per_customer_limit) {
                return false;
            }

            return $this->validator->customerIneligibility($coupon, $customerUserId) === null;
        })->values();
    }

    /**
     * Public coupons that cover at least one of these sellable offers.
     *
     * @param  Collection<int, Listing>  $offers
     * @return Collection<int, Coupon>
     */
    public function forOffers(Collection $offers, ?int $customerUserId): Collection
    {
        return $this->forShopper($customerUserId)->filter(fn (Coupon $coupon): bool => $offers->contains(
            fn (Listing $listing): bool => ($coupon->scope !== CouponScope::Vendor || (int) $coupon->vendor_id === (int) $listing->vendor_id) && $this->targets->covers($coupon, $listing)
        ))->values();
    }

    /**
     * @param  Collection<int, Coupon>  $coupons
     * @return array<int, array<string, mixed>>
     */
    public function present(Collection $coupons, ?int $customerUserId): array
    {
        $vendors = Vendor::query()->whereIn('id', $coupons->pluck('vendor_id')->filter())->get(['id', 'slug', 'store_name'])->keyBy('id');
        $names = $this->targetNames($coupons);

        return $coupons->map(fn (Coupon $coupon): array => [
            'code' => $coupon->code,
            'label' => $this->label($coupon),
            'description' => $coupon->description,
            'type' => $coupon->type->value,
            'percent' => in_array($coupon->type, [CouponType::Percentage, CouponType::Bogo], true) ? rtrim(rtrim((string) $coupon->value, '0'), '.') : null,
            'amount' => $coupon->type === CouponType::FixedAmount ? MoneyResource::make((string) $coupon->value) : null,
            'buy_quantity' => $coupon->buy_quantity,
            'get_quantity' => $coupon->get_quantity,
            'min_order' => MoneyResource::make($coupon->min_order_amount === null ? null : (string) $coupon->min_order_amount),
            'max_discount' => MoneyResource::make($coupon->max_discount_amount === null ? null : (string) $coupon->max_discount_amount),
            'scope' => $coupon->scope->value,
            'seller' => $coupon->vendor_id === null || ! $vendors->has($coupon->vendor_id) ? null : ['slug' => $vendors[$coupon->vendor_id]->slug, 'name' => $vendors[$coupon->vendor_id]->store_name],
            'applies_to' => [
                'type' => ($coupon->target_type ?? CouponTarget::All)->value,
                'items' => $names[$coupon->id] ?? [],
            ],
            'first_order_only' => $coupon->first_order_only,
            'requires_sign_in' => $customerUserId === null && $coupon->needsCustomer(),
            'ends_at' => $coupon->ends_at?->toIso8601String(),
        ])->all();
    }

    /**
     * @return Builder<Coupon>
     */
    private function candidates(): Builder
    {
        return Coupon::query()
            ->where('is_public', true)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->where(fn ($query) => $query->whereNull('usage_limit')->orWhere('usage_limit', '>', 1))
            ->where(fn ($query) => $query->whereNull('vendor_id')->orWhereIn('vendor_id', Vendor::query()->select('id')->where('status', Vendor::STATUS_ACTIVE)))
            ->withCount('redemptions')
            ->orderByRaw('ends_at is null')->orderBy('ends_at')->orderByDesc('id')
            ->limit(100);
    }

    /**
     * Guests are shown first-order and new-customer coupons as a reason to sign up.
     */
    private function guestTeaser(Coupon $coupon): bool
    {
        return in_array($coupon->customer_segment ?? CustomerSegment::Everyone, [CustomerSegment::Everyone, CustomerSegment::NewCustomers], true);
    }

    private function label(Coupon $coupon): string
    {
        $percent = rtrim(rtrim((string) $coupon->value, '0'), '.');

        return match ($coupon->type) {
            CouponType::Percentage => __(':percent% off', ['percent' => $percent]),
            CouponType::FixedAmount => __(':amount off', ['amount' => implode(' ', array_reverse(MoneyResource::make((string) $coupon->value)))]),
            CouponType::FreeShipping => __('Free shipping'),
            CouponType::Bogo => $percent === '100'
                ? __('Buy :buy get :get free', ['buy' => $coupon->buy_quantity, 'get' => $coupon->get_quantity])
                : __('Buy :buy get :get at :percent% off', ['buy' => $coupon->buy_quantity, 'get' => $coupon->get_quantity, 'percent' => $percent]),
        };
    }

    /**
     * Names of what each targeted coupon covers (up to 10 per coupon).
     *
     * @param  Collection<int, Coupon>  $coupons
     * @return array<int, array<int, array{slug: string, name: string}>>
     */
    private function targetNames(Collection $coupons): array
    {
        $ids = fn (CouponTarget $target): array => $coupons->filter(fn (Coupon $coupon): bool => $coupon->target_type === $target)->flatMap->targetIds()->unique()->values()->all();
        $lookup = [
            CouponTarget::Category->value => Category::query()->whereIn('id', $ids(CouponTarget::Category))->get()->mapWithKeys(fn (Category $category): array => [$category->id => ['slug' => $category->slug, 'name' => $category->name()]]),
            CouponTarget::Brand->value => Brand::query()->whereIn('id', $ids(CouponTarget::Brand))->get()->mapWithKeys(fn (Brand $brand): array => [$brand->id => ['slug' => $brand->slug, 'name' => $brand->name]]),
            CouponTarget::Product->value => Product::query()->whereIn('id', $ids(CouponTarget::Product))->where('status', 'active')->get()->mapWithKeys(fn (Product $product): array => [$product->id => ['slug' => $product->slug, 'name' => $product->title()]]),
            CouponTarget::Vendor->value => Vendor::query()->whereIn('id', $ids(CouponTarget::Vendor))->where('status', Vendor::STATUS_ACTIVE)->get()->mapWithKeys(fn (Vendor $vendor): array => [$vendor->id => ['slug' => $vendor->slug, 'name' => $vendor->store_name]]),
        ];
        $names = [];

        foreach ($coupons as $coupon) {
            $map = $lookup[($coupon->target_type ?? CouponTarget::All)->value] ?? null;
            $names[$coupon->id] = $map === null ? [] : collect($coupon->targetIds())->filter(fn (int $id): bool => $map->has($id))->take(10)->map(fn (int $id): array => $map[$id])->values()->all();
        }

        return $names;
    }
}
