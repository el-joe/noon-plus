<?php

namespace App\Domains\Content\Http\Controllers\Admin;

use App\Domains\Advertising\Models\AdSlot;
use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Support\ListingCards;
use App\Domains\Content\Enums\PageTemplate;
use App\Domains\Content\Models\Page;
use App\Domains\Content\Support\BlockTiles;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\FlashSales\Models\FlashSaleEvent;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\Listing;
use App\Domains\LiveMedia\Models\LiveStream;
use App\Domains\Vendors\Models\Vendor;
use App\Http\Controllers\Controller;
use App\Shared\Http\PublicUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Small JSON endpoints of the page editor: live search for its pickers
 * (`q` to search, `ids[]` to label saved values) and image uploads to the
 * public disk. Every row is `{value, label, sub, image, …fills}`.
 */
class PageBuilderPickerController extends Controller
{
    private const LIMIT = 20;

    public function __construct(private CountryContext $countryContext) {}

    public function search(Request $request, string $kind, BlockTiles $tiles): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'ids' => ['nullable', 'array', 'max:60'], 'ids.*' => ['nullable', 'string', 'max:190']]);
        $search = trim((string) ($data['q'] ?? ''));
        $ids = array_values(array_filter((array) ($data['ids'] ?? []), fn ($id): bool => is_string($id) && $id !== ''));
        $like = '%'.addcslashes($search, '%_\\').'%';

        $rows = match ($kind) {
            'product' => $this->products($search, $like, $ids),
            'category' => Category::query()->where('is_active', true)
                ->when($ids !== [], fn (Builder $query) => $query->whereIn('slug', $ids), fn (Builder $query) => $query->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match->where('name_en', 'like', $like)->orWhere('name_ar', 'like', $like)->orWhere('slug', 'like', $like))))
                ->orderBy('parent_id')->orderBy('sort_order')->limit(self::LIMIT)->get()
                ->map(fn (Category $category): array => [
                    'value' => $category->slug, 'label' => $category->name(), 'sub' => $tiles->path($category), 'image' => PublicUrl::of($category->image_path),
                    'name_en' => $category->name_en, 'name_ar' => $category->name_ar, 'link' => $tiles->path($category),
                ]),
            'brand' => Brand::query()->where('is_active', true)
                ->when($ids !== [], fn (Builder $query) => $query->whereIn('slug', $ids), fn (Builder $query) => $query->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match->where('name', 'like', $like)->orWhere('name_ar', 'like', $like)->orWhere('slug', 'like', $like))))
                ->orderBy('name')->limit(self::LIMIT)->get()
                ->map(fn (Brand $brand): array => [
                    'value' => $brand->slug, 'label' => $brand->name, 'sub' => $brand->name_ar, 'image' => PublicUrl::of($brand->logo_path),
                    'name_en' => $brand->name, 'name_ar' => $brand->name_ar ?: $brand->name, 'link' => '/brands/'.$brand->slug,
                ]),
            'coupon' => Coupon::query()
                ->when($ids !== [], fn (Builder $query) => $query->whereIn('code', $ids), fn (Builder $query) => $query->where('is_active', true)->whereNull('admin_disabled_at')
                    ->where(fn (Builder $live) => $live->whereNull('ends_at')->orWhere('ends_at', '>', now()))
                    ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match->where('code', 'like', $like)->orWhere('description', 'like', $like))))
                ->orderByDesc('id')->limit(self::LIMIT)->get()
                ->map(fn (Coupon $coupon): array => ['value' => $coupon->code, 'label' => $coupon->code, 'sub' => Str::limit((string) $coupon->description, 80), 'image' => null]),
            'flash_sale' => FlashSaleEvent::query()
                ->when($ids !== [], fn (Builder $query) => $query->whereIn('slug', $ids), fn (Builder $query) => $query->where('ends_at', '>', now()->subDays(30))
                    ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match->where('name', 'like', $like)->orWhere('slug', 'like', $like))))
                ->orderByDesc('starts_at')->limit(self::LIMIT)->get()
                ->map(fn (FlashSaleEvent $event): array => [
                    'value' => $event->slug, 'label' => $event->display_name ?: $event->name, 'image' => null,
                    'sub' => ucfirst($event->status->value).' · '.$event->starts_at?->format('j M').' – '.$event->ends_at?->format('j M Y'),
                ]),
            'ad_slot' => AdSlot::query()->where('is_active', true)
                ->when($ids !== [], fn (Builder $query) => $query->whereIn('placement', $ids), fn (Builder $query) => $query->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match->where('name', 'like', $like)->orWhere('placement', 'like', $like)->orWhere('code', 'like', $like))))
                ->orderBy('placement')->limit(60)->get()->unique('placement')->take(self::LIMIT)
                ->map(fn (AdSlot $slot): array => ['value' => $slot->placement, 'label' => $slot->placement, 'sub' => $slot->name, 'image' => null]),
            'stream' => LiveStream::query()
                ->when($ids !== [], fn (Builder $query) => $query->whereIn('slug', $ids), fn (Builder $query) => $query->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match->where('title_en', 'like', $like)->orWhere('title_ar', 'like', $like)->orWhere('slug', 'like', $like))))
                ->orderByDesc('id')->limit(self::LIMIT)->get()
                ->map(fn (LiveStream $stream): array => ['value' => $stream->slug, 'label' => $stream->title(), 'sub' => ucfirst($stream->status->value), 'image' => PublicUrl::of($stream->thumbnail_path)]),
            'vendor' => Vendor::query()
                ->when($ids !== [], fn (Builder $query) => $query->whereIn('slug', $ids), fn (Builder $query) => $query->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match->where('store_name', 'like', $like)->orWhere('slug', 'like', $like))))
                ->orderBy('store_name')->limit(self::LIMIT)->get()
                ->map(fn (Vendor $vendor): array => [
                    'value' => $vendor->slug, 'label' => $vendor->store_name, 'sub' => $vendor->slug, 'image' => PublicUrl::of($vendor->logo_path),
                    'name' => $vendor->store_name, 'link' => '/seller/'.$vendor->slug,
                ]),
            'link' => $this->links($search, $like, $tiles),
            default => abort(404),
        };

        return response()->json(['data' => collect($rows)->values()->all()]);
    }

    /**
     * Image upload for image fields: stored on the public disk, answered with its URL.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate(['image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif,avif', 'max:5120']]);
        $path = $request->file('image')->store('page-builder/'.$this->countryContext->current()->code.'/'.now()->format('Y-m'), 'public');

        return response()->json(['url' => Storage::disk('public')->url($path)]);
    }

    /**
     * Live listings by product title, or the given listing ids in their order.
     *
     * @param  array<int, string>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function products(string $search, string $like, array $ids): array
    {
        $listings = Listing::query()->with(ListingCards::relations())
            ->when($ids !== [], fn (Builder $query) => $query->whereKey(array_map('intval', $ids)), fn (Builder $query) => $query->where('status', ListingStatus::Active)
                ->when($search !== '', fn (Builder $query) => $query->whereHas('variant.product', fn (Builder $product) => $product->where(fn (Builder $match) => $match->where('title_en', 'like', $like)->orWhere('title_ar', 'like', $like)->orWhere('slug', 'like', $like)))))
            ->orderByDesc('id')->limit($ids !== [] ? 60 : self::LIMIT)->get();

        return $listings->map(function (Listing $listing): array {
            $card = ListingCards::card($listing);

            return [
                'value' => (string) $listing->id,
                'label' => $card['title'] ?? '#'.$listing->id,
                'sub' => trim(($card['brand'] ?? '').' · '.($card['price'] ? $card['price']['currency'].' '.rtrim(rtrim((string) $card['price']['amount'], '0'), '.') : '').' · #'.$listing->id.($listing->status !== ListingStatus::Active ? ' · '.__('not live') : ''), ' ·'),
                'image' => $card['image']['url'] ?? null,
            ];
        })->all();
    }

    /**
     * Link picker: pages, categories, brands, products and flash sales as storefront paths.
     *
     * @return array<int, array<string, mixed>>
     */
    private function links(string $search, string $like, BlockTiles $tiles): array
    {
        if ($search === '') {
            return Page::query()->where('locale', app()->getLocale() === 'ar' ? 'ar' : 'en')->orderBy('slug')->limit(self::LIMIT)->get()
                ->map(fn (Page $page): array => ['value' => $page->template === PageTemplate::Home ? '/' : '/'.$page->slug, 'label' => $page->title, 'sub' => __('Page'), 'image' => null])->all();
        }

        $pages = Page::query()->where(fn (Builder $match) => $match->where('title', 'like', $like)->orWhere('slug', 'like', $like))->limit(5)->get()->unique('slug')
            ->map(fn (Page $page): array => ['value' => $page->template === PageTemplate::Home ? '/' : '/'.$page->slug, 'label' => $page->title, 'sub' => __('Page'), 'image' => null]);
        $categories = Category::query()->where('is_active', true)->where(fn (Builder $match) => $match->where('name_en', 'like', $like)->orWhere('name_ar', 'like', $like)->orWhere('slug', 'like', $like))->limit(6)->get()
            ->map(fn (Category $category): array => ['value' => $tiles->path($category), 'label' => $category->name(), 'sub' => __('Category'), 'image' => PublicUrl::of($category->image_path)]);
        $brands = Brand::query()->where('is_active', true)->where(fn (Builder $match) => $match->where('name', 'like', $like)->orWhere('slug', 'like', $like))->limit(4)->get()
            ->map(fn (Brand $brand): array => ['value' => '/brands/'.$brand->slug, 'label' => $brand->name, 'sub' => __('Brand'), 'image' => PublicUrl::of($brand->logo_path)]);
        $sales = FlashSaleEvent::query()->where('ends_at', '>', now())->where(fn (Builder $match) => $match->where('name', 'like', $like)->orWhere('slug', 'like', $like))->limit(3)->get()
            ->map(fn (FlashSaleEvent $event): array => ['value' => '/flash-sale/'.$event->slug, 'label' => $event->display_name ?: $event->name, 'sub' => __('Flash sale'), 'image' => null]);
        $products = collect($this->products($search, $like, []))->take(5)->map(fn (array $row): array => [...$row, 'value' => '/products/'.(Listing::query()->with('variant.product')->find((int) $row['value'])?->variant?->product?->slug ?? ''), 'sub' => __('Product')]);

        return $pages->concat($categories)->concat($brands)->concat($sales)->concat($products)->values()->all();
    }
}
