<?php

namespace App\Domains\Fulfilment\Support;

use App\Domains\Delivery\Support\DeliveryDispatch;
use App\Domains\Delivery\Support\DeliverySettings;
use App\Domains\Fulfilment\Models\FbnReservation;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Exceptions\SubOrderTransitionRefused;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Orders\Support\SubOrderStateMachine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The warehouse side of FBN orders (admin → Inventory & fulfilment →
 * Pick & pack). A sub-order whose every item is an FBN listing with
 * warehouse units reserved is fulfilled by the warehouse: it confirms,
 * picks, packs and dispatches it through the {@see SubOrderStateMachine}
 * (the seller cannot advance it). Dispatch moves the sub-order to shipped
 * (reserved units leave the stock) and opens its shipment, which then
 * follows the normal delivery flow (dispatch board, agents, carriers).
 * A seller's checkout group mixing FBN and seller-shipped items is split at
 * checkout into two sub-orders (owner decision 2026-11-19, see
 * {@see \App\Domains\Orders\Support\PlaceOrder}): the warehouse parcel
 * (`fulfilled_by` = `nawy`) comes here, the seller ships the other one.
 * Mixed sub-orders placed before the split stay with the seller; their FBN
 * units are taken from the warehouse when the seller hands the parcel over.
 */
class FbnOrders
{
    public function __construct(private SubOrderStateMachine $machine, private DeliveryDispatch $dispatch, private DeliverySettings $deliverySettings) {}

    public function isWarehouseFulfilled(SubOrder $subOrder): bool
    {
        if ($subOrder->fulfilled_by === 'nawy') {
            return true;
        }

        return FbnReservation::query()->where('sub_order_id', $subOrder->id)->exists()
            && ! $subOrder->items()->whereHas('listing', fn (Builder $listing) => $listing->where('fulfilment', '!=', 'fbn'))->exists();
    }

    /**
     * Warehouse-fulfilled sub-orders still to ship.
     *
     * @return Builder<SubOrder>
     */
    public function queue(): Builder
    {
        return SubOrder::query()
            ->whereIn('status', [SubOrderStatus::Placed, SubOrderStatus::Confirmed, SubOrderStatus::Processing, SubOrderStatus::Packed])
            ->where(fn (Builder $query) => $query->where('fulfilled_by', 'nawy')->orWhere(fn (Builder $legacy) => $legacy
                ->whereIn('id', FbnReservation::query()->select('sub_order_id'))
                ->whereDoesntHave('items.listing', fn (Builder $listing) => $listing->where('fulfilment', '!=', 'fbn'))));
    }

    /**
     * The one warehouse step from a status.
     */
    public function nextStep(SubOrderStatus $status): ?SubOrderStatus
    {
        return match ($status) {
            SubOrderStatus::Placed => SubOrderStatus::Confirmed,
            SubOrderStatus::Confirmed => SubOrderStatus::Processing,
            SubOrderStatus::Processing => SubOrderStatus::Packed,
            SubOrderStatus::Packed => SubOrderStatus::Shipped,
            default => null,
        };
    }

    public function stepLabel(SubOrderStatus $to): string
    {
        return match ($to) {
            SubOrderStatus::Confirmed => __('Confirm order'),
            SubOrderStatus::Processing => __('Start picking'),
            SubOrderStatus::Packed => __('Mark as packed'),
            SubOrderStatus::Shipped => __('Dispatch'),
            default => $to->value,
        };
    }

    public function advance(SubOrder $subOrder, SubOrderStatus $to, int $actorUserId): SubOrder
    {
        if (! $this->isWarehouseFulfilled($subOrder)) {
            throw new SubOrderTransitionRefused(__('This order is not fulfilled by the warehouse.'));
        }

        if ($this->nextStep($subOrder->status) !== $to) {
            throw new SubOrderTransitionRefused(__('This order cannot move to :status from here.', ['status' => $to->value]));
        }

        $moved = DB::connection($subOrder->getConnectionName())->transaction(function () use ($subOrder, $to, $actorUserId): SubOrder {
            $moved = $this->machine->transition($subOrder, $to, ActorType::Admin, $actorUserId, $to === SubOrderStatus::Shipped ? 'Dispatched from the FBN warehouse' : null);

            if ($to === SubOrderStatus::Shipped) {
                $this->dispatch->shipmentFor($moved, ActorType::Admin, $actorUserId);
            }

            return $moved;
        });

        if ($to === SubOrderStatus::Shipped && $this->deliverySettings->autoAssign() !== 'off') {
            try {
                $this->dispatch->autoAssign($moved, ActorType::System);
            } catch (Throwable) {
                // No agent serves the zone yet: the shipment waits on the dispatch board.
            }
        }

        return $moved;
    }
}
