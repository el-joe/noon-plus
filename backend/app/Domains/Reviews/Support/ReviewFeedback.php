<?php

namespace App\Domains\Reviews\Support;

use App\Domains\Orders\Enums\ActorType;
use App\Domains\Reviews\Enums\ReplyStatus;
use App\Domains\Reviews\Enums\ReviewStatus;
use App\Domains\Reviews\Models\ProductReview;
use App\Domains\Reviews\Models\ProductReviewReply;
use App\Domains\Reviews\Models\ProductReviewReport;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Vendor replies and review reports (QC-012, lifecycle §2.9).
 * - The reviewed store may reply once to a published review; the reply is
 *   moderated (pending → published / rejected) and can be rewritten after
 *   a rejection.
 * - A signed-in customer (not the author) or the reviewed store may report
 *   a published review once; the first report flags it for moderation.
 */
class ReviewFeedback
{
    public function __construct(
        private StateMachine $machine,
        private ContentModeration $moderation,
        private ReviewAutoFlag $autoFlag,
    ) {}

    public function reply(Vendor $vendor, int $userId, ProductReview $review, string $body): ProductReviewReply
    {
        if ($review->vendor_id !== $vendor->id) {
            throw new TransitionRefused(__('You can reply only to reviews of your store.'));
        }

        if (! $review->status->isPublic()) {
            throw new TransitionRefused(__('You can reply only to published reviews.'));
        }

        $body = trim($body);
        $flag = $this->autoFlag->reason($body);

        return DB::connection($review->getConnectionName())->transaction(function () use ($vendor, $userId, $review, $body, $flag): ProductReviewReply {
            $reply = ProductReviewReply::query()->where('product_review_id', $review->id)->lockForUpdate()->first();

            if ($reply === null) {
                $reply = ProductReviewReply::query()->create([
                    'product_review_id' => $review->id,
                    'vendor_id' => $vendor->id,
                    'author_user_id' => $userId,
                    'body' => $body,
                    'status' => ReplyStatus::Pending,
                ]);
                $this->machine->created($reply, ActorType::Vendor, $userId, $flag);

                return $reply;
            }

            if ($reply->status !== ReplyStatus::Rejected) {
                throw new TransitionRefused(__('This review already has a reply.'));
            }

            $reply->forceFill(['body' => $body, 'author_user_id' => $userId])->save();

            return $this->machine->move($reply, ReplyStatus::Pending, ActorType::Vendor, $userId, $flag, ['rejection_reason' => null]);
        });
    }

    public function report(ProductReview $review, int $userId, string $reporterType, string $reason, ?string $note = null, ?Vendor $vendor = null): ProductReviewReport
    {
        if (! $review->status->isPublic()) {
            throw new TransitionRefused(__('This review is not published.'));
        }

        if ($reporterType === 'customer' && $review->customer_user_id === $userId) {
            throw new TransitionRefused(__('You cannot report your own review.'));
        }

        if ($reporterType === 'vendor' && $review->vendor_id !== $vendor?->id) {
            throw new TransitionRefused(__('You can report only reviews of your store.'));
        }

        return DB::connection($review->getConnectionName())->transaction(function () use ($review, $userId, $reporterType, $reason, $note, $vendor): ProductReviewReport {
            try {
                $report = ProductReviewReport::query()->create([
                    'product_review_id' => $review->id,
                    'reporter_user_id' => $userId,
                    'reporter_type' => $reporterType,
                    'vendor_id' => $vendor?->id,
                    'reason' => $reason,
                    'note' => $note === null ? null : mb_substr(trim($note), 0, 500),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new TransitionRefused(__('You have already reported this review.'));
            }

            $review->newQuery()->whereKey($review->id)->increment('report_count');

            if ($review->status === ReviewStatus::Published) {
                $this->moderation->flag($review, $reporterType === 'vendor' ? ActorType::Vendor : ActorType::Customer, $userId, __('Reported: :reason', ['reason' => $reason]));
            }

            return $report;
        });
    }
}
