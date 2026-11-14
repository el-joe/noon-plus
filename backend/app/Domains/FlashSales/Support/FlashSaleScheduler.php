<?php

namespace App\Domains\FlashSales\Support;

use App\Domains\FlashSales\Enums\FlashSaleEventStatus;
use App\Domains\FlashSales\Enums\FlashSaleSubmissionStatus;
use App\Domains\FlashSales\Models\FlashSaleEvent;
use App\Domains\FlashSales\Models\FlashSaleSubmission;
use App\Domains\Orders\Enums\ActorType;

/**
 * Time-driven flash-sale transitions for the current country (SRS §17,
 * TransitionFlashSaleStatusJob / FlashSaleSchedulerJob every 5 minutes):
 * open submissions, close them into review, go live, end, and mark
 * live submissions that reached their cap as sold out.
 */
class FlashSaleScheduler
{
    public function __construct(
        private FlashSaleEventMachine $events,
        private FlashSaleSubmissionMachine $submissions,
    ) {}

    /**
     * @return array<string, int> transition => count
     */
    public function tick(): array
    {
        $counts = ['opened' => 0, 'closed' => 0, 'live' => 0, 'ended' => 0, 'sold_out' => 0];
        $now = now();

        $due = fn (FlashSaleEventStatus $status) => FlashSaleEvent::query()->where('status', $status)->orderBy('id');

        foreach ($due(FlashSaleEventStatus::Draft)->whereNotNull('submission_starts_at')->where('submission_starts_at', '<=', $now)->where('ends_at', '>', $now)->get() as $event) {
            $this->events->transition($event, FlashSaleEventStatus::SubmissionOpen, ActorType::System);
            $counts['opened']++;
        }

        foreach ($due(FlashSaleEventStatus::SubmissionOpen)->get() as $event) {
            if ($event->submissionDeadline()->lessThanOrEqualTo($now)) {
                $this->events->transition($event, FlashSaleEventStatus::SubmissionClosed, ActorType::System);
                $counts['closed']++;
            }
        }

        foreach ($due(FlashSaleEventStatus::SubmissionClosed)->get() as $event) {
            $this->events->transition($event, FlashSaleEventStatus::UnderReview, ActorType::System);
        }

        foreach ($due(FlashSaleEventStatus::Approved)->where('starts_at', '<=', $now)->get() as $event) {
            $this->events->transition($event, FlashSaleEventStatus::Live, ActorType::System);
            $counts['live']++;
        }

        foreach ($due(FlashSaleEventStatus::Live)->where('ends_at', '<=', $now)->get() as $event) {
            $this->events->transition($event, FlashSaleEventStatus::Ended, ActorType::System);
            $counts['ended']++;
        }

        $soldOut = FlashSaleSubmission::query()
            ->where('status', FlashSaleSubmissionStatus::Live)
            ->whereColumn('sold_quantity', '>=', 'quantity_cap')
            ->get();

        foreach ($soldOut as $submission) {
            $this->submissions->transition($submission, FlashSaleSubmissionStatus::SoldOut, ActorType::System);
            $counts['sold_out']++;
        }

        return $counts;
    }
}
