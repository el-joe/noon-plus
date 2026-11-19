<?php

namespace App\Domains\Reviews\Support;

use App\Domains\Orders\Enums\ActorType;
use App\Domains\Reviews\Enums\ModerationStatus;
use App\Domains\Reviews\Enums\ReplyStatus;
use App\Domains\Reviews\Enums\ReviewStatus;
use App\Domains\Reviews\Models\ProductAnswer;
use App\Domains\Reviews\Models\ProductQuestion;
use App\Domains\Reviews\Models\ProductReview;
use App\Domains\Reviews\Models\ProductReviewReply;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use BackedEnum;
use Illuminate\Support\Facades\DB;

/**
 * The moderation machines for reviews ({@see ReviewStatus}, catalogue
 * §2.9), vendor replies ({@see ReplyStatus}) and questions / answers
 * ({@see ModerationStatus}) (ADR 7): the enum decides the allowed moves,
 * rejecting or hiding needs a reason, every move writes a history row with
 * the actor, and a review move refreshes the product and vendor ratings.
 */
class ContentModeration
{
    public function __construct(private StateMachine $machine, private RatingAggregates $aggregates) {}

    /**
     * Publish a review or reply; approve a question or answer.
     */
    public function approve(ProductReview|ProductReviewReply|ProductQuestion|ProductAnswer $subject, ?int $actorUserId, ActorType $actorType = ActorType::Admin): void
    {
        $to = match (true) {
            $subject instanceof ProductReview => ReviewStatus::Published,
            $subject instanceof ProductReviewReply => ReplyStatus::Published,
            default => ModerationStatus::Approved,
        };

        $this->move($subject, $to, $actorUserId, null, $actorType);
    }

    public function reject(ProductReview|ProductReviewReply|ProductQuestion|ProductAnswer $subject, ?int $actorUserId, string $reason): void
    {
        $to = match (true) {
            $subject instanceof ProductReview => ReviewStatus::Rejected,
            $subject instanceof ProductReviewReply => ReplyStatus::Rejected,
            default => ModerationStatus::Rejected,
        };

        $this->move($subject, $to, $actorUserId, $this->reason($reason), ActorType::Admin);
    }

    /**
     * Take a review off the site without rejecting it (it can be restored).
     */
    public function hide(ProductReview $review, ?int $actorUserId, string $reason): void
    {
        $this->move($review, ReviewStatus::Hidden, $actorUserId, $this->reason($reason), ActorType::Admin);
    }

    /**
     * A report moves a published review to `flagged` (lifecycle §2.9).
     */
    public function flag(ProductReview $review, ActorType $actorType, int $actorUserId, string $reason): void
    {
        $this->move($review, ReviewStatus::Flagged, $actorUserId, $reason, $actorType);
    }

    private function reason(string $reason): string
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw new TransitionRefused(__('Give a reason for the rejection.'));
        }

        return trim($reason);
    }

    private function move(ProductReview|ProductReviewReply|ProductQuestion|ProductAnswer $subject, BackedEnum $to, ?int $actorUserId, ?string $reason, ActorType $actorType): void
    {
        DB::connection($subject->getConnectionName())->transaction(function () use ($subject, $to, $actorUserId, $reason, $actorType): void {
            $attributes = match (true) {
                $to === ReviewStatus::Flagged => ['flag_reason' => $reason],
                default => [
                    'rejection_reason' => in_array($to, [ReviewStatus::Rejected, ReviewStatus::Hidden, ReplyStatus::Rejected, ModerationStatus::Rejected], true) ? $reason : null,
                    'moderated_at' => now(),
                    'moderated_by_user_id' => $actorUserId,
                ],
            };

            $this->machine->move($subject, $to, $actorType, $actorUserId, $reason, $attributes);

            if ($subject instanceof ProductReview) {
                $this->aggregates->refresh($subject);
            }
        });
    }
}
