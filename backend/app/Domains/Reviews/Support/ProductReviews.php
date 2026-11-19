<?php

namespace App\Domains\Reviews\Support;

use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Reviews\Enums\ReviewStatus;
use App\Domains\Reviews\Models\ProductReview;
use App\Domains\Reviews\Models\ProductReviewPhoto;
use App\Models\User;
use App\Shared\Notifications\Email\PartnerEmails;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Writing reviews and helpful votes (G-W3). Only the buyer of an order
 * item whose shipment was delivered (or completed) can review it, once
 * per item; the review waits for moderation.
 */
class ProductReviews
{
    public const MAX_PHOTOS = 5;

    public function __construct(private StateMachine $machine, private ReviewAutoFlag $autoFlag) {}

    /**
     * The customer's delivered order items in the current country.
     *
     * @return Builder<OrderItem>
     */
    public function deliveredItems(int $customerUserId): Builder
    {
        return OrderItem::query()
            ->whereHas('subOrder', fn (Builder $subOrder) => $subOrder
                ->whereIn('status', [SubOrderStatus::Delivered->value, SubOrderStatus::Completed->value])
                ->whereHas('order', fn (Builder $order) => $order->where('customer_user_id', $customerUserId)));
    }

    /**
     * @param  array{rating: int, title?: string|null, body?: string|null}  $data
     * @param  array<int, UploadedFile>  $photos
     */
    public function create(User $customer, int $orderItemId, array $data, array $photos = []): ProductReview
    {
        $item = $this->deliveredItems($customer->id)->with(['listing.variant', 'subOrder'])->find($orderItemId)
            ?? throw new TransitionRefused(__('You can review only items delivered to you.'));

        if (ProductReview::query()->where('order_item_id', $item->id)->exists()) {
            throw new TransitionRefused(__('You have already reviewed this item.'));
        }

        $stored = [];
        $flagReason = $this->autoFlag->reason($data['title'] ?? null, $data['body'] ?? null);

        try {
            return DB::connection($item->getConnectionName())->transaction(function () use ($customer, $item, $data, $photos, $flagReason, &$stored): ProductReview {
                $review = ProductReview::query()->create([
                    'product_id' => $item->listing->variant->product_id,
                    'vendor_id' => $item->subOrder->vendor_id,
                    'order_item_id' => $item->id,
                    'customer_user_id' => $customer->id,
                    'customer_name' => self::displayName((string) $customer->name),
                    'rating' => $data['rating'],
                    'title' => $data['title'] ?? null,
                    'body' => $data['body'] ?? null,
                    'locale' => app()->getLocale() === 'ar' ? 'ar' : 'en',
                    'status' => $flagReason === null ? ReviewStatus::Pending : ReviewStatus::AutoFlagged,
                    'flag_reason' => $flagReason,
                ]);

                foreach (array_values(array_slice($photos, 0, self::MAX_PHOTOS)) as $index => $photo) {
                    $stored[] = $path = $photo->store('reviews/'.$review->product_id, ProductReviewPhoto::DISK);
                    $review->photos()->create(['path' => $path, 'sort_order' => $index]);
                }

                $this->machine->created($review, ActorType::Customer, $customer->id, $flagReason);
                $product = $item->listing->variant->product;
                app(PartnerEmails::class)->toVendor((int) $review->vendor_id, 'seller.review_received', fn (string $locale): array => [
                    'product' => (string) ($locale === 'ar' ? $product?->title_ar : $product?->title_en),
                    'rating' => $review->rating.'/5',
                ], '/reviews', ['entity' => $review]);

                return $review;
            });
        } catch (\Throwable $failure) {
            Storage::disk(ProductReviewPhoto::DISK)->delete($stored);

            throw $failure;
        }
    }

    /**
     * Mark (or unmark) an approved review as helpful; not on one's own review.
     */
    public function vote(ProductReview $review, int $userId, bool $helpful): ProductReview
    {
        if (! $review->status->isPublic()) {
            throw new TransitionRefused(__('This review is not published.'));
        }

        if ($review->customer_user_id === $userId) {
            throw new TransitionRefused(__('You cannot vote on your own review.'));
        }

        DB::connection($review->getConnectionName())->transaction(function () use ($review, $userId, $helpful): void {
            $changed = $helpful
                ? $review->votes()->insertOrIgnore(['product_review_id' => $review->id, 'user_id' => $userId, 'created_at' => now()])
                : $review->votes()->where('user_id', $userId)->delete();

            if ($changed > 0) {
                $review->newQuery()->whereKey($review->id)->update(['helpful_count' => $review->votes()->count()]);
            }
        });

        return $review->refresh();
    }

    /**
     * "Sara Ahmed" → "Sara A." (reviews never show a full name).
     */
    public static function displayName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            return __('Nawy customer');
        }

        $first = mb_substr($parts[0], 0, 60);

        return count($parts) > 1 ? $first.' '.mb_strtoupper(mb_substr(end($parts), 0, 1)).'.' : $first;
    }
}
