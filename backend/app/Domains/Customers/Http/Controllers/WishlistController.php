<?php

namespace App\Domains\Customers\Http\Controllers;

use App\Domains\Catalog\Http\Resources\ProductCardResource;
use App\Domains\Catalog\Models\Product;
use App\Domains\Customers\Models\WishlistItem;
use App\Domains\Listings\Support\BuyBox;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Wishlist API (G-W6): the customer's saved products in this country as
 * product cards (newest first) with `in_stock` and `saved_at`; add by
 * product slug (idempotent), remove by slug.
 */
class WishlistController extends Controller
{
    public const MAX_ITEMS = 500;

    public function __construct(private BuyBox $buyBox) {}

    public function index(Request $request): JsonResponse
    {
        $page = WishlistItem::query()
            ->where('customer_user_id', $request->user()->id)
            ->whereHas('product', fn ($product) => $product->where('status', 'active'))
            ->latest('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 48))));
        $savedAt = collect($page->items())->mapWithKeys(fn (WishlistItem $item): array => [$item->product_id => $item->created_at?->toIso8601String()]);
        $products = Product::query()
            ->whereIn('products.id', $savedAt->keys())
            ->addSelect(['products.*', 'best_price' => $this->buyBox->bestPriceSubquery()])
            ->with(['brand', 'category', 'media'])
            ->get()
            ->keyBy('id');

        $cards = $savedAt->keys()->filter(fn ($id): bool => $products->has($id))->map(fn ($id): array => (new ProductCardResource($products[$id]))->resolve($request) + [
            'in_stock' => $products[$id]->best_price !== null,
            'saved_at' => $savedAt[$id],
        ])->values();

        return ApiResponse::success($cards, ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()]);
    }

    public function store(Request $request): JsonResponse
    {
        $slug = $request->validate(['product' => ['required', 'string', 'max:255']])['product'];
        $product = Product::query()->where('slug', $slug)->where('status', 'active')->first();

        if ($product === null) {
            return ApiResponse::error('validation_failed', __('That product does not exist.'), 422, ['product' => [__('That product does not exist.')]]);
        }

        $userId = $request->user()->id;

        if (! WishlistItem::query()->where('customer_user_id', $userId)->where('product_id', $product->id)->exists()
            && WishlistItem::query()->where('customer_user_id', $userId)->count() >= self::MAX_ITEMS) {
            return ApiResponse::error('wishlist_full', __('Your wishlist is full.'), 422);
        }

        $item = WishlistItem::query()->firstOrCreate(['customer_user_id' => $userId, 'product_id' => $product->id]);

        return ApiResponse::success(['product' => $product->slug, 'saved' => true, 'saved_at' => $item->created_at?->toIso8601String()], status: $item->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, string $slug): JsonResponse
    {
        $productId = Product::query()->where('slug', $slug)->value('id');

        if ($productId !== null) {
            WishlistItem::query()->where('customer_user_id', $request->user()->id)->where('product_id', $productId)->delete();
        }

        return ApiResponse::success(['product' => $slug, 'saved' => false]);
    }
}
