<?php

namespace App\Domains\Catalog\Http\Controllers;

use App\Domains\Catalog\Http\Requests\ProductIndexRequest;
use App\Domains\Catalog\Http\Resources\CategoryResource;
use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Catalog\Http\Resources\OfferResource;
use App\Domains\Catalog\Http\Resources\ProductCardResource;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\FlashSales\Support\FlashSalePricing;
use App\Domains\Listings\Support\BuyBox;
use App\Domains\Shipping\Support\ListingDeliveryPromise;
use App\Domains\Warranty\Support\WarrantyPlanCatalog;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Public catalogue API for the storefront and the customer app.
 */
class CatalogController extends Controller
{
    public function __construct(private BuyBox $buyBox) {}

    public function categories(): JsonResponse
    {
        $roots = Category::query()
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->with(['children' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return ApiResponse::success(CategoryResource::collection($roots));
    }

    public function products(ProductIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $query = Product::query()
            ->where('products.status', 'active')
            ->addSelect(['products.*', 'best_price' => $this->buyBox->bestPriceSubquery()])
            ->whereExists($this->buyBox->hasSellableOfferSubquery())
            ->with(['brand', 'category'])
            ->when($filters['q'] ?? null, fn (Builder $query, string $term) => $query->where(
                fn (Builder $search) => $search->where('title_en', 'like', '%'.$term.'%')->orWhere('title_ar', 'like', '%'.$term.'%')
            ))
            ->when($filters['category'] ?? null, fn (Builder $query, string $slug) => $query->whereHas(
                'category',
                fn (Builder $category) => $category->where('slug', $slug)
                    ->orWhereIn('parent_id', Category::query()->select('id')->where('slug', $slug))
            ))
            ->when($filters['brand'] ?? null, fn (Builder $query, string $slug) => $query->whereHas('brand', fn (Builder $brand) => $brand->where('slug', $slug)))
            ->when(isset($filters['min_price']), fn (Builder $query) => $query->where($this->buyBox->bestPriceSubquery(), '>=', $filters['min_price']))
            ->when(isset($filters['max_price']), fn (Builder $query) => $query->where($this->buyBox->bestPriceSubquery(), '<=', $filters['max_price']));

        match ($filters['sort'] ?? 'recommended') {
            'price_asc' => $query->orderBy('best_price')->orderBy('products.id'),
            'price_desc' => $query->orderByDesc('best_price')->orderBy('products.id'),
            default => $query->orderByDesc('products.created_at')->orderByDesc('products.id'),
        };

        $page = $query->paginate($filters['per_page'] ?? 24);

        return ApiResponse::success(ProductCardResource::collection($page->items()), [
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]);
    }

    public function product(string $slug, FlashSalePricing $flashPricing, ListingDeliveryPromise $deliveryPromise, WarrantyPlanCatalog $warrantyPlans): JsonResponse
    {
        $product = Product::query()->where('slug', $slug)->where('status', 'active')->with(['brand', 'category', 'variants'])->firstOrFail();
        $offers = $this->buyBox->offersFor($product);
        $deals = $flashPricing->activeDeals($offers->modelKeys());
        $offerResources = $offers->map(function ($offer) use ($product, $deals, $deliveryPromise): OfferResource {
            $offer->variant->setRelation('product', $product);

            return new OfferResource($offer, $deals->get($offer->id), $deliveryPromise->estimate($offer));
        });
        $plans = $warrantyPlans->forCategories([$product->category_id])->get($product->category_id, collect());

        return ApiResponse::success([
            'slug' => $product->slug,
            'title' => $product->title(),
            'brand' => $product->brand?->name,
            'category' => new CategoryResource($product->category),
            'highlights' => $product->highlights ?? [],
            'specifications' => $product->specifications ?? [],
            'fulfilment_mode' => $product->fulfilment_mode,
            'lead_days' => $product->lead_days,
            'buy_box' => $offerResources->first(),
            'other_offers' => $offerResources->slice(1)->values(),
            'from_price' => MoneyResource::make($offers->first()?->effectivePrice()),
            'warranty_plans' => $plans->map(fn ($plan): array => [
                'plan_id' => $plan->id,
                'name' => $plan->name(),
                'months' => $plan->duration_months,
                'price' => MoneyResource::make((string) $plan->price),
            ])->values(),
        ]);
    }
}
