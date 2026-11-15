<?php

namespace App\Domains\Reviews\Http\Controllers;

use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductMedia;
use App\Domains\Reviews\Enums\ModerationStatus;
use App\Domains\Reviews\Http\Requests\ReviewIndexRequest;
use App\Domains\Reviews\Http\Requests\StoreReviewRequest;
use App\Domains\Reviews\Http\Resources\ReviewResource;
use App\Domains\Reviews\Models\ProductReview;
use App\Domains\Reviews\Models\ProductReviewVote;
use App\Domains\Reviews\Support\ProductReviews;
use App\Domains\Reviews\Support\RatingAggregates;
use App\Domains\Vendors\Models\Vendor;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reviews & ratings API (G-W3): product and seller rating summaries with
 * published reviews, verified-buyer review submission (moderated), the
 * customer's own reviews and items waiting for a review, helpful votes.
 */
class ReviewController extends Controller
{
    public function __construct(private ProductReviews $reviews, private RatingAggregates $aggregates) {}

    public function forProduct(ReviewIndexRequest $request, string $slug): JsonResponse
    {
        $product = Product::query()->where('slug', $slug)->where('status', 'active')->firstOrFail();

        return $this->published($request, ProductReview::query()->where('product_id', $product->id));
    }

    public function forSeller(ReviewIndexRequest $request, string $slug): JsonResponse
    {
        $vendor = Vendor::query()->where('slug', $slug)->where('status', Vendor::STATUS_ACTIVE)->firstOrFail();

        return $this->published($request, ProductReview::query()->where('vendor_id', $vendor->id), [
            'seller' => ['slug' => $vendor->slug, 'name' => $vendor->store_name],
        ]);
    }

    public function store(StoreReviewRequest $request): JsonResponse
    {
        try {
            $review = $this->reviews->create($request->user(), (int) $request->validated('order_item_id'), $request->validated(), $request->file('photos', []));
        } catch (TransitionRefused $refused) {
            return ApiResponse::error('review_not_allowed', $refused->getMessage(), 422);
        }

        return ApiResponse::success(new ReviewResource($review->load(['photos', 'vendor', 'product']), forAuthor: true), status: 201);
    }

    public function mine(Request $request): JsonResponse
    {
        $page = ProductReview::query()
            ->where('customer_user_id', $request->user()->id)
            ->with(['photos', 'vendor', 'product'])
            ->latest('id')
            ->paginate(20);

        return ApiResponse::success(
            collect($page->items())->map(fn (ProductReview $review): ReviewResource => new ReviewResource($review, forAuthor: true))->all(),
            ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()],
        );
    }

    /**
     * Delivered items the customer has not reviewed yet.
     */
    public function reviewable(Request $request): JsonResponse
    {
        $items = $this->reviews->deliveredItems($request->user()->id)
            ->whereNotIn('id', ProductReview::query()->select('order_item_id')->where('customer_user_id', $request->user()->id))
            ->with(['subOrder.order', 'listing.variant.product.media'])
            ->latest('id')
            ->limit(50)
            ->get();

        return ApiResponse::success($items->map(function ($item): array {
            $product = $item->listing?->variant?->product;
            $image = $product?->primaryImage($item->listing->product_variant_id);

            return [
                'order_item_id' => $item->id,
                'order_number' => $item->subOrder->order->number,
                'shipment_number' => $item->subOrder->number,
                'title' => $item->title,
                'product' => $product === null ? null : ['slug' => $product->slug, 'title' => $product->title()],
                'image' => $image instanceof ProductMedia ? $image->toPublicArray() : null,
            ];
        })->values());
    }

    public function helpful(Request $request, int $review): JsonResponse
    {
        return $this->vote($request, $review, true);
    }

    public function unhelpful(Request $request, int $review): JsonResponse
    {
        return $this->vote($request, $review, false);
    }

    private function vote(Request $request, int $reviewId, bool $helpful): JsonResponse
    {
        $review = ProductReview::query()->where('status', ModerationStatus::Approved)->findOrFail($reviewId);

        try {
            $review = $this->reviews->vote($review, $request->user()->id, $helpful);
        } catch (TransitionRefused $refused) {
            return ApiResponse::error('vote_not_allowed', $refused->getMessage(), 422);
        }

        return ApiResponse::success(['id' => $review->id, 'helpful_count' => $review->helpful_count, 'voted_helpful' => $helpful]);
    }

    /**
     * @param  Builder<ProductReview>  $scope
     * @param  array<string, mixed>  $extra
     */
    private function published(ReviewIndexRequest $request, Builder $scope, array $extra = []): JsonResponse
    {
        $filters = $request->validated();
        $query = (clone $scope)->where('status', ModerationStatus::Approved)
            ->with(['photos', 'vendor'])
            ->when($filters['rating'] ?? null, fn (Builder $query, int|string $rating) => $query->where('rating', (int) $rating))
            ->when($request->boolean('with_photos'), fn (Builder $query) => $query->has('photos'));

        match ($filters['sort'] ?? 'recent') {
            'helpful' => $query->orderByDesc('helpful_count')->orderByDesc('id'),
            'rating_desc' => $query->orderByDesc('rating')->orderByDesc('id'),
            'rating_asc' => $query->orderBy('rating')->orderByDesc('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };

        $page = $query->paginate($filters['per_page'] ?? 10);
        $viewerId = $request->user('sanctum')?->id;
        $voted = $viewerId === null ? [] : ProductReviewVote::query()
            ->where('user_id', $viewerId)
            ->whereIn('product_review_id', collect($page->items())->pluck('id'))
            ->pluck('product_review_id')->map(fn ($id): int => (int) $id)->all();

        return ApiResponse::success($extra + [
            'summary' => $this->aggregates->summary($scope),
            'reviews' => collect($page->items())->map(fn (ProductReview $review): array => (new ReviewResource($review, $voted))->resolve($request))->all(),
        ], ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()]);
    }
}
