<?php

namespace App\Domains\Advertising\Http\Controllers\Api;

use App\Domains\Advertising\Enums\AdBookingStatus;
use App\Domains\Advertising\Models\AdBooking;
use App\Domains\Advertising\Models\AdCampaign;
use App\Domains\Advertising\Support\AdCampaigns;
use App\Domains\Advertising\Support\AdvertiserBilling;
use App\Domains\Advertising\Support\SponsoredRanker;
use App\Domains\Catalog\Http\Resources\ProductCardResource;
use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Support\CardOffers;
use App\Domains\Catalog\Support\ProductSearch;
use App\Domains\Vendors\Models\Vendor;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\PublicUrl;
use App\Shared\Settings\VerticalSettings;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * Ad delivery for the storefront and the app: sponsored product clicks
 * (the encrypted `ad_token` from {@see SponsoredRanker}) and the live paid
 * placements of a page (homepage / category slots), always labelled "Ad".
 */
class AdController extends Controller
{
    public function click(Request $request, AdCampaigns $campaigns): JsonResponse
    {
        $token = $request->validate(['token' => ['required', 'string', 'max:2048']])['token'];

        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return ApiResponse::error('invalid_ad_token', __('This ad link is not valid.'), 422);
        }

        $campaign = AdCampaign::query()->findOrFail((int) ($payload['c'] ?? 0));
        $visitor = $request->user('sanctum')?->id !== null ? 'u'.$request->user('sanctum')->id : ($request->header('X-Visitor-Token') ?? $request->ip());
        $event = $campaigns->click($campaign, (string) ($payload['pl'] ?? 'search'), $visitor);

        return ApiResponse::success(['product' => $payload['p'] ?? null, 'billable' => $event->is_billable]);
    }

    /**
     * Live bookings of a placement. `category_id` keeps slots of exactly
     * that category; `category` (a slug) keeps slots of that category, any
     * of its parents, or no category. `with=products` adds the advertised
     * brand (from a `/brands/{slug}` or `brand=` link) or the advertising
     * store's top products for the sponsored brand row of the category page.
     */
    public function placements(Request $request, string $placement, VerticalSettings $settings): JsonResponse
    {
        $filters = $request->validate(['category_id' => ['nullable', 'integer'], 'category' => ['nullable', 'string', 'max:255'], 'with' => ['nullable', 'in:products']]);
        $category = $filters['category_id'] ?? null;
        $lineage = isset($filters['category']) ? array_map(fn (Category $node): int => (int) $node->id, Category::query()->where('slug', $filters['category'])->first()?->lineage() ?? []) : null;
        $label = app()->getLocale() === 'ar' ? $settings->string('advertising.label_ar') : $settings->string('advertising.label_en');
        $bookings = AdBooking::query()->where('status', AdBookingStatus::Active)
            ->whereDate('starts_on', '<=', today())->whereDate('ends_on', '>=', today())
            ->whereHas('slot', fn ($slot) => $slot->where('placement', $placement)
                ->when($category !== null, fn ($query) => $query->where('category_id', $category))
                ->when($lineage !== null, fn ($query) => $query->where(fn ($scoped) => $scoped->whereNull('category_id')->orWhereIn('category_id', $lineage ?: [0]))))
            ->with('slot')->orderBy('id')->get();

        return ApiResponse::success($bookings->map(fn (AdBooking $booking): array => [
            'slot' => $booking->slot->code,
            'image' => $booking->creative_path === null || $booking->creative_type === 'video' ? null : Storage::disk('public')->url($booking->creative_path),
            // QC-018: admin-uploaded video creative (ADR 11).
            'video' => $booking->creative_path !== null && $booking->creative_type === 'video' ? Storage::disk('public')->url($booking->creative_path) : null,
            'creative_type' => $booking->creative_type,
            'headline' => $booking->headline,
            'link_url' => $booking->link_url,
            'sponsored' => true,
            'label' => $label,
            ...(($filters['with'] ?? null) === 'products' ? $this->showcase($request, $booking, $filters['category'] ?? null) : []),
        ])->all());
    }

    /**
     * The advertised brand (or store) and up to 4 of its product cards.
     *
     * @return array{brand: array{slug: string, name: string, logo: string|null}|null, store: array{slug: string, name: string}|null, products: array<int, array<string, mixed>>}
     */
    private function showcase(Request $request, AdBooking $booking, ?string $category): array
    {
        $search = app(ProductSearch::class);
        $link = (string) $booking->link_url;
        $brandSlug = preg_match('~/brands/([a-z0-9-]+)~', $link, $match) || preg_match('~[?&]brand=([a-z0-9-]+)~', $link, $match) ? $match[1] : null;
        $brand = $brandSlug === null ? null : Brand::query()->where('slug', $brandSlug)->where('is_active', true)->first();
        $store = $brand === null && $booking->advertiser_type === AdvertiserBilling::VENDOR ? Vendor::query()->find($booking->advertiser_id) : null;
        $filters = $brand !== null ? ['brand' => $brand->slug] : ($store !== null ? ['seller' => $store->slug] : null);
        $products = collect();

        if ($filters !== null) {
            $scoped = $category !== null ? $search->query($filters + ['category' => $category]) : null;
            $query = $scoped !== null && (clone $scoped)->exists() ? $scoped : $search->query($filters);
            $products = $query->with(['brand', 'category', 'media'])
                ->orderByRaw('products.bestseller_rank is null')->orderBy('products.bestseller_rank')->orderByDesc('products.rating_count')->orderByDesc('products.id')
                ->limit(4)->get();
            CardOffers::attach($products);
        }

        return [
            'brand' => $brand === null ? null : ['slug' => $brand->slug, 'name' => $brand->name, 'logo' => PublicUrl::of($brand->logo_path)],
            'store' => $store === null ? null : ['slug' => $store->slug, 'name' => $store->store_name],
            'products' => $products->filter(fn (Product $product): bool => $product->best_listing_id !== null)
                ->map(fn (Product $product): array => (new ProductCardResource($product))->resolve($request))->values()->all(),
        ];
    }
}
