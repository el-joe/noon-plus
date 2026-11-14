<?php

namespace App\Domains\FlashSales\Support;

use App\Domains\FlashSales\Enums\FlashSaleEventStatus;
use App\Domains\FlashSales\Enums\FlashSaleSubmissionStatus;
use App\Domains\FlashSales\Exceptions\FlashSaleTransitionRefused;
use App\Domains\FlashSales\Models\FlashSaleEvent;
use App\Domains\Orders\Enums\ActorType;
use Illuminate\Support\Facades\DB;

/**
 * The only way a flash-sale event changes status (lifecycle catalogue §4.10).
 * Moves the event's submissions along: closing submissions sends them to
 * review, going live publishes approved ones (and rejects unreviewed ones),
 * ending ends them, cancelling withdraws them.
 */
class FlashSaleEventMachine
{
    public function __construct(private FlashSaleSubmissionMachine $submissions) {}

    public function transition(FlashSaleEvent $event, FlashSaleEventStatus $to, ActorType $actorType, ?int $actorUserId = null, ?string $reason = null): FlashSaleEvent
    {
        $from = $event->status;

        if (! $from->canMoveTo($to)) {
            throw FlashSaleTransitionRefused::notAllowed($from->value, $to->value);
        }

        if ($to === FlashSaleEventStatus::Live && $event->starts_at->isFuture()) {
            throw FlashSaleTransitionRefused::because('A flash sale cannot go live before its start time.');
        }

        if ($to === FlashSaleEventStatus::SubmissionOpen && ! $event->ends_at->isFuture()) {
            throw FlashSaleTransitionRefused::because('A flash sale that has already ended cannot open for submissions.');
        }

        return DB::connection($event->getConnectionName())->transaction(function () use ($event, $from, $to, $actorType, $actorUserId, $reason): FlashSaleEvent {
            $event->forceFill(['status' => $to])->save();
            $event->statusHistory()->create([
                'from_status' => $from,
                'to_status' => $to,
                'actor_type' => $actorType,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
            ]);

            $this->moveSubmissions($event, $to, $actorType, $actorUserId);

            return $event;
        });
    }

    private function moveSubmissions(FlashSaleEvent $event, FlashSaleEventStatus $to, ActorType $actorType, ?int $actorUserId): void
    {
        $moves = match ($to) {
            FlashSaleEventStatus::UnderReview => [
                FlashSaleSubmissionStatus::Submitted->value => [FlashSaleSubmissionStatus::UnderReview, null],
                FlashSaleSubmissionStatus::Draft->value => [FlashSaleSubmissionStatus::Withdrawn, 'Not submitted before the deadline'],
            ],
            FlashSaleEventStatus::Live => [
                FlashSaleSubmissionStatus::Approved->value => [FlashSaleSubmissionStatus::Live, null],
                FlashSaleSubmissionStatus::UnderReview->value => [FlashSaleSubmissionStatus::Rejected, 'Not approved before the event started'],
                FlashSaleSubmissionStatus::Submitted->value => [FlashSaleSubmissionStatus::Withdrawn, 'Not reviewed before the event started'],
                FlashSaleSubmissionStatus::Draft->value => [FlashSaleSubmissionStatus::Withdrawn, 'Not submitted before the event started'],
            ],
            FlashSaleEventStatus::Ended => [
                FlashSaleSubmissionStatus::Live->value => [FlashSaleSubmissionStatus::Ended, null],
            ],
            FlashSaleEventStatus::Cancelled => [
                FlashSaleSubmissionStatus::Draft->value => [FlashSaleSubmissionStatus::Withdrawn, 'Event cancelled'],
                FlashSaleSubmissionStatus::Submitted->value => [FlashSaleSubmissionStatus::Withdrawn, 'Event cancelled'],
                FlashSaleSubmissionStatus::UnderReview->value => [FlashSaleSubmissionStatus::Withdrawn, 'Event cancelled'],
                FlashSaleSubmissionStatus::Approved->value => [FlashSaleSubmissionStatus::Withdrawn, 'Event cancelled'],
            ],
            default => [],
        };

        if ($moves === []) {
            return;
        }

        $submissions = $event->submissions()->whereIn('status', array_keys($moves))->orderBy('id')->get();

        foreach ($submissions as $submission) {
            [$next, $reason] = $moves[$submission->status->value];
            $this->submissions->transition($submission, $next, $to === FlashSaleEventStatus::Cancelled ? $actorType : ActorType::System, $to === FlashSaleEventStatus::Cancelled ? $actorUserId : null, $reason);
        }
    }
}
