<?php

namespace App\Domains\Reviews\Http\Resources;

use App\Domains\Reviews\Enums\ReplyStatus;
use App\Domains\Reviews\Enums\ReviewStatus;
use App\Domains\Reviews\Models\ProductReview;
use App\Domains\Reviews\Models\ProductReviewPhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A review as shown on the product page and in the customer's own list
 * (`status` and `rejection_reason` are only for the author).
 *
 * @mixin ProductReview
 */
class ReviewResource extends JsonResource
{
    /**
     * @param  array<int, int>  $votedIds  review ids the viewer marked helpful
     */
    public function __construct(ProductReview $resource, private array $votedIds = [], private bool $forAuthor = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'title' => $this->title,
            'body' => $this->body,
            'locale' => $this->locale,
            'author' => $this->customer_name,
            'verified_purchase' => true,
            'photos' => $this->photos->map(fn (ProductReviewPhoto $photo): array => ['id' => $photo->id, 'url' => $photo->url()])->all(),
            'helpful_count' => (int) $this->helpful_count,
            'voted_helpful' => in_array($this->id, $this->votedIds, true),
            'seller' => $this->vendor?->store_name,
            // QC-012: the store's reply, once Nawy published it.
            'seller_reply' => $this->relationLoaded('reply') && $this->reply?->status === ReplyStatus::Published
                ? ['body' => $this->reply->body, 'created_at' => $this->reply->created_at->toIso8601String()]
                : null,
            'created_at' => $this->created_at->toIso8601String(),
            $this->mergeWhen($this->forAuthor, fn (): array => [
                // QC-012: the author sees `pending`, `published` or `rejected`/`hidden`; flags stay internal.
                'status' => match ($this->status) {
                    ReviewStatus::AutoFlagged => ReviewStatus::Pending->value,
                    ReviewStatus::Flagged => ReviewStatus::Published->value,
                    default => $this->status->value,
                },
                'rejection_reason' => $this->rejection_reason,
                'product' => ['slug' => $this->product?->slug, 'title' => $this->product?->title()],
                'order_item_id' => $this->order_item_id,
            ]),
        ];
    }
}
