<?php

namespace App\Domains\Listings\Support;

use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Exceptions\ListingTransitionRefused;
use App\Domains\Listings\Models\Listing;
use Illuminate\Support\Facades\DB;

/**
 * The only way a listing changes status. Every change is checked against the
 * allowed transitions and written to the history with the actor (ADR 7).
 */
class ListingStateMachine
{
    public function transition(Listing $listing, ListingStatus $to, ?int $actorUserId = null, ?string $reason = null): Listing
    {
        $from = $listing->status;

        if (! $from->canMoveTo($to)) {
            throw ListingTransitionRefused::notAllowed($from, $to);
        }

        $this->guard($listing, $to, $reason);

        return DB::connection($listing->getConnectionName())->transaction(function () use ($listing, $from, $to, $actorUserId, $reason): Listing {
            $listing->forceFill([
                'status' => $to,
                'rejection_reason' => $to === ListingStatus::Rejected ? $reason : null,
            ])->save();

            $listing->statusHistory()->create([
                'from_status' => $from,
                'to_status' => $to,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
            ]);

            return $listing;
        });
    }

    private function guard(Listing $listing, ListingStatus $to, ?string $reason): void
    {
        if ($to === ListingStatus::PendingReview && bccomp((string) $listing->price, '0', 4) <= 0) {
            throw ListingTransitionRefused::because('A listing needs a price above zero before review.');
        }

        if ($to === ListingStatus::Active && ! $listing->vendor->isActive()) {
            throw ListingTransitionRefused::because('The seller account is not active.');
        }

        if ($to === ListingStatus::Rejected && blank($reason)) {
            throw ListingTransitionRefused::because('A rejection needs a reason for the seller.');
        }
    }
}
