<?php

namespace App\Domains\Customers\Listeners;

use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Customers\Support\CustomerNotifications;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\RealEstate\Models\PropertyViewing;
use App\Domains\Returns\Models\ReturnRequest;
use App\Domains\Reviews\Models\ProductQuestion;
use App\Domains\Reviews\Models\ProductReview;
use App\Domains\Travel\Models\TravelBooking;
use App\Shared\StateMachine\StateMoved;

/**
 * Fills the customer's notification centre from lifecycle moves (G-W29):
 * sub-order status, travel booking decisions, return progress, viewing /
 * reserve request answers, classified ad review and product review / Q&A
 * moderation. Moves the customer made themselves are not echoed back.
 * Links are storefront paths without the market prefix.
 */
class NotifyCustomerOfStateChange
{
    private const ORDER_STATUSES = ['confirmed', 'shipped', 'out_for_delivery', 'delivered', 'cancelled', 'returned', 'refunded'];

    public function __construct(private CustomerNotifications $notifications) {}

    public function handle(StateMoved $event): void
    {
        $notice = match (true) {
            $event->subject instanceof SubOrder => $this->subOrder($event),
            $event->subject instanceof TravelBooking => $this->booking($event),
            $event->subject instanceof ReturnRequest => $this->returnRequest($event),
            $event->subject instanceof PropertyViewing => $this->viewing($event),
            $event->subject instanceof ClassifiedAd => $this->classifiedAd($event),
            $event->subject instanceof ProductReview => $this->review($event),
            $event->subject instanceof ProductQuestion => $this->question($event),
            default => null,
        };

        if ($notice === null || $notice['user_id'] === null) {
            return;
        }

        if ($event->actorType === ActorType::Customer && $event->actorUserId === $notice['user_id']) {
            return;
        }

        $this->notifications->notify((int) $notice['user_id'], $notice['type'], $notice['title'], $notice['body'] ?? null, $notice['link'] ?? null, $notice['data'] ?? []);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function subOrder(StateMoved $event): ?array
    {
        if (! in_array($event->to->value, self::ORDER_STATUSES, true)) {
            return null;
        }

        /** @var SubOrder $subOrder */
        $subOrder = $event->subject;
        $order = $subOrder->order()->first(['id', 'number', 'customer_user_id']);
        $status = str_replace('_', ' ', $event->to->value);

        return [
            'user_id' => $order?->customer_user_id,
            'type' => 'order.'.$event->to->value,
            'title' => __('Your order :number is :status', ['number' => $order?->number, 'status' => $status]),
            'body' => $event->to->value === 'cancelled' && $event->reason !== null ? __('Reason: :reason', ['reason' => $event->reason]) : __('Shipment :number is now :status.', ['number' => $subOrder->number, 'status' => $status]),
            'link' => '/account/orders/'.$order?->number,
            'data' => ['order' => $order?->number, 'shipment' => $subOrder->number, 'status' => $event->to->value],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function booking(StateMoved $event): ?array
    {
        if (! in_array($event->to->value, ['confirmed', 'rejected', 'cancelled', 'completed'], true)) {
            return null;
        }

        /** @var TravelBooking $booking */
        $booking = $event->subject;

        return [
            'user_id' => $booking->customer_user_id,
            'type' => 'travel_booking.'.$event->to->value,
            'title' => __('Your trip booking :number is :status', ['number' => $booking->number, 'status' => $event->to->value]),
            'body' => $event->reason,
            'link' => '/travel/bookings/'.$booking->number,
            'data' => ['booking' => $booking->number, 'status' => $event->to->value],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function returnRequest(StateMoved $event): ?array
    {
        /** @var ReturnRequest $return */
        $return = $event->subject;

        return [
            'user_id' => $return->customer_user_id,
            'type' => 'return.'.$event->to->value,
            'title' => __('Your return :number is :status', ['number' => $return->number, 'status' => str_replace('_', ' ', $event->to->value)]),
            'body' => $event->to->value === 'rejected' ? $event->reason : null,
            'link' => '/account/returns/'.$return->number,
            'data' => ['return' => $return->number, 'status' => $event->to->value],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function viewing(StateMoved $event): ?array
    {
        /** @var PropertyViewing $viewing */
        $viewing = $event->subject;
        $ad = $viewing->ad()->first(['id', 'number', 'title']);
        $kind = $viewing->type === 'reserve' ? __('reserve request') : __('viewing request');

        return [
            'user_id' => $viewing->customer_user_id,
            'type' => ($viewing->type === 'reserve' ? 'reserve.' : 'viewing.').$event->to->value,
            'title' => __('Your :kind for :title is :status', ['kind' => $kind, 'title' => $ad?->title, 'status' => str_replace('_', ' ', $event->to->value)]),
            'body' => $viewing->scheduled_at === null ? null : __('Time: :at', ['at' => $viewing->scheduled_at->toDayDateTimeString()]),
            'link' => '/account/inquiries',
            'data' => ['request_id' => $viewing->id, 'ad' => $ad?->number, 'status' => $event->to->value],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function classifiedAd(StateMoved $event): ?array
    {
        if (! in_array($event->to->value, ['active', 'rejected', 'expired'], true) || $event->actorType === ActorType::Customer) {
            return null;
        }

        /** @var ClassifiedAd $ad */
        $ad = $event->subject;

        return [
            'user_id' => $ad->owner_user_id,
            'type' => 'classified_ad.'.$event->to->value,
            'title' => match ($event->to->value) {
                'active' => __('Your ad ":title" is live', ['title' => $ad->title]),
                'rejected' => __('Your ad ":title" was not approved', ['title' => $ad->title]),
                default => __('Your ad ":title" has expired', ['title' => $ad->title]),
            },
            'body' => $event->to->value === 'rejected' ? $event->reason : null,
            'link' => '/classified/find/'.$ad->number,
            'data' => ['ad' => $ad->number, 'status' => $event->to->value],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function review(StateMoved $event): ?array
    {
        if (! in_array($event->to->value, ['approved', 'rejected'], true)) {
            return null;
        }

        /** @var ProductReview $review */
        $review = $event->subject;
        $slug = $review->product()->value('slug');

        return [
            'user_id' => $review->customer_user_id,
            'type' => 'review.'.$event->to->value,
            'title' => $event->to->value === 'approved' ? __('Your review is published') : __('Your review was not published'),
            'body' => $event->to->value === 'rejected' ? $event->reason : null,
            'link' => $slug === null ? '/account/reviews' : '/products/'.$slug,
            'data' => ['review_id' => $review->id, 'product' => $slug, 'status' => $event->to->value],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function question(StateMoved $event): ?array
    {
        if (! in_array($event->to->value, ['approved', 'rejected'], true)) {
            return null;
        }

        /** @var ProductQuestion $question */
        $question = $event->subject;
        $slug = $question->product()->value('slug');

        return [
            'user_id' => $question->customer_user_id,
            'type' => 'question.'.$event->to->value,
            'title' => $event->to->value === 'approved' ? __('Your question is published') : __('Your question was not published'),
            'body' => $event->to->value === 'rejected' ? $event->reason : null,
            'link' => $slug === null ? null : '/products/'.$slug,
            'data' => ['question_id' => $question->id, 'product' => $slug, 'status' => $event->to->value],
        ];
    }
}
