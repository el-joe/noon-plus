<?php

namespace App\Domains\Fulfilment\Support;

use App\Domains\Fulfilment\Enums\InboundStatus;
use App\Domains\Fulfilment\Enums\RemovalStatus;
use App\Domains\Fulfilment\Models\InboundLine;
use App\Domains\Fulfilment\Models\RemovalLine;
use App\Domains\Fulfilment\Models\WarehouseStock;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\InventoryLedger;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Support\Facades\DB;

/**
 * Seller FBN enrolment per listing (`listings.fulfilment` fbm ↔ fbn). An
 * FBN listing sells warehouse stock only, so enrolling takes the seller's
 * own declared units off sale (they reach the warehouse with an inbound
 * plan); going back to FBM needs the warehouse empty for that listing.
 */
class FbnEnrolment
{
    public function __construct(private InventoryLedger $ledger) {}

    public function enrol(Listing $listing, ?int $actorUserId): Listing
    {
        if ($listing->status === ListingStatus::Archived) {
            throw new TransitionRefused(__('An archived listing cannot be changed.'));
        }

        if ($listing->fulfilment === 'fbn') {
            return $listing;
        }

        return DB::connection($listing->getConnectionName())->transaction(function () use ($listing, $actorUserId): Listing {
            $listing->forceFill(['fulfilment' => 'fbn'])->save();
            $declared = $this->ledger->available($listing);

            if ($declared > 0) {
                $this->ledger->move($listing, -$declared, 'fbn_enrolment', 'listing', $listing->id, $actorUserId);
            }

            return $listing->refresh();
        });
    }

    public function leave(Listing $listing, ?int $actorUserId): Listing
    {
        if ($listing->fulfilment !== 'fbn') {
            return $listing;
        }

        $units = (int) WarehouseStock::query()->where('listing_id', $listing->id)->sum(DB::raw('on_hand + damaged'));
        $openInbound = InboundLine::query()->where('listing_id', $listing->id)
            ->whereHas('shipment', fn ($shipment) => $shipment->whereIn('status', [InboundStatus::Draft, InboundStatus::Submitted, InboundStatus::InTransit, InboundStatus::Arrived, InboundStatus::Receiving]))->exists();
        $openRemoval = RemovalLine::query()->where('listing_id', $listing->id)
            ->whereHas('order', fn ($order) => $order->whereIn('status', [RemovalStatus::Requested, RemovalStatus::Processing]))->exists();

        if ($units > 0 || $openInbound || $openRemoval) {
            throw new TransitionRefused(__('Remove the warehouse stock (and close open inbound plans and removals) before switching back to fulfilment by you.'));
        }

        $listing->forceFill(['fulfilment' => 'fbm'])->save();

        return $listing;
    }
}
