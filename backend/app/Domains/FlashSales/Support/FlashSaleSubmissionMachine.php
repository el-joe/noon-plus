<?php

namespace App\Domains\FlashSales\Support;

use App\Domains\FlashSales\Enums\FlashSaleEventStatus;
use App\Domains\FlashSales\Enums\FlashSaleSubmissionStatus;
use App\Domains\FlashSales\Exceptions\FlashSaleTransitionRefused;
use App\Domains\FlashSales\Models\FlashSaleEvent;
use App\Domains\FlashSales\Models\FlashSaleSubmission;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Orders\Enums\ActorType;
use Illuminate\Support\Facades\DB;

/**
 * The only way a flash-sale submission changes status (lifecycle catalogue
 * §4.11). Submitting needs an event open for submissions, a flash price
 * below the listing's current price and a cap the stock can cover.
 */
class FlashSaleSubmissionMachine
{
    public function __construct(private InventoryLedger $inventoryLedger) {}

    /**
     * A seller offers a listing to an event (draft → submitted in one step).
     */
    public function submit(FlashSaleEvent $event, Listing $listing, string $flashPrice, int $quantityCap, ?int $actorUserId = null): FlashSaleSubmission
    {
        return DB::connection($event->getConnectionName())->transaction(function () use ($event, $listing, $flashPrice, $quantityCap, $actorUserId): FlashSaleSubmission {
            $submission = FlashSaleSubmission::query()->firstOrNew(['flash_sale_event_id' => $event->id, 'listing_id' => $listing->id]);

            if ($submission->exists && $submission->status !== FlashSaleSubmissionStatus::Draft) {
                throw FlashSaleTransitionRefused::because('This listing is already in the flash sale.');
            }

            $submission->fill([
                'vendor_id' => $listing->vendor_id,
                'original_price' => $listing->effectivePrice(),
                'flash_price' => $flashPrice,
                'quantity_cap' => $quantityCap,
            ])->save();

            if ($submission->wasRecentlyCreated) {
                $submission->statusHistory()->create(['to_status' => FlashSaleSubmissionStatus::Draft, 'actor_type' => ActorType::Vendor, 'actor_user_id' => $actorUserId]);
            }

            return $this->transition($submission, FlashSaleSubmissionStatus::Submitted, ActorType::Vendor, $actorUserId);
        });
    }

    public function transition(FlashSaleSubmission $submission, FlashSaleSubmissionStatus $to, ActorType $actorType, ?int $actorUserId = null, ?string $reason = null): FlashSaleSubmission
    {
        $from = $submission->status;

        if (! $from->canMoveTo($to)) {
            throw FlashSaleTransitionRefused::notAllowed($from->value, $to->value);
        }

        $this->guard($submission, $to, $reason);

        return DB::connection($submission->getConnectionName())->transaction(function () use ($submission, $from, $to, $actorType, $actorUserId, $reason): FlashSaleSubmission {
            $submission->forceFill([
                'status' => $to,
                'rejection_reason' => $to === FlashSaleSubmissionStatus::Rejected ? $reason : $submission->rejection_reason,
            ])->save();
            $submission->statusHistory()->create([
                'from_status' => $from,
                'to_status' => $to,
                'actor_type' => $actorType,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
            ]);

            return $submission;
        });
    }

    private function guard(FlashSaleSubmission $submission, FlashSaleSubmissionStatus $to, ?string $reason): void
    {
        if ($to === FlashSaleSubmissionStatus::Rejected && blank($reason)) {
            throw FlashSaleTransitionRefused::because('A rejection needs a reason for the seller.');
        }

        if ($to !== FlashSaleSubmissionStatus::Submitted) {
            return;
        }

        $listing = $submission->listing;

        if ($submission->event->status !== FlashSaleEventStatus::SubmissionOpen) {
            throw FlashSaleTransitionRefused::because('This flash sale is not open for submissions.');
        }

        if (! $listing->status->isSellable()) {
            throw FlashSaleTransitionRefused::because('Only active listings can join a flash sale.');
        }

        if (bccomp((string) $submission->flash_price, '0', 4) <= 0 || bccomp((string) $submission->flash_price, $listing->effectivePrice(), 4) >= 0) {
            throw FlashSaleTransitionRefused::because('The flash price must be above zero and below the current price.');
        }

        if ($submission->quantity_cap < 1 || $submission->quantity_cap > $this->inventoryLedger->available($listing)) {
            throw FlashSaleTransitionRefused::because('The flash quantity must be between 1 and the stock you have.');
        }
    }
}
