<?php

namespace App\Domains\Reviews\Support;

use App\Domains\Orders\Enums\ActorType;
use App\Domains\Reviews\Enums\ModerationStatus;
use App\Domains\Reviews\Models\ProductAnswer;
use App\Domains\Reviews\Models\ProductQuestion;
use App\Domains\Reviews\Models\ProductReview;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Support\Facades\DB;

/**
 * The moderation machine for reviews, questions and answers (ADR 7):
 * {@see ModerationStatus} decides the allowed moves, a rejection needs a
 * reason, every move writes a history row with the admin, and a review
 * move refreshes the product and vendor rating aggregates.
 */
class ContentModeration
{
    public function __construct(private StateMachine $machine, private RatingAggregates $aggregates) {}

    public function approve(ProductReview|ProductQuestion|ProductAnswer $subject, ?int $actorUserId, ActorType $actorType = ActorType::Admin): void
    {
        $this->move($subject, ModerationStatus::Approved, $actorUserId, null, $actorType);
    }

    public function reject(ProductReview|ProductQuestion|ProductAnswer $subject, ?int $actorUserId, string $reason): void
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw new TransitionRefused(__('Give a reason for the rejection.'));
        }

        $this->move($subject, ModerationStatus::Rejected, $actorUserId, trim($reason), ActorType::Admin);
    }

    private function move(ProductReview|ProductQuestion|ProductAnswer $subject, ModerationStatus $to, ?int $actorUserId, ?string $reason, ActorType $actorType): void
    {
        DB::connection($subject->getConnectionName())->transaction(function () use ($subject, $to, $actorUserId, $reason, $actorType): void {
            $this->machine->move($subject, $to, $actorType, $actorUserId, $reason, [
                'rejection_reason' => $to === ModerationStatus::Rejected ? $reason : null,
                'moderated_at' => now(),
                'moderated_by_user_id' => $actorUserId,
            ]);

            if ($subject instanceof ProductReview) {
                $this->aggregates->refresh($subject);
            }
        });
    }
}
