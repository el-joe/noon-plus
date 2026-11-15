<?php

namespace App\Domains\Orders\Support;

use App\Domains\Delivery\Enums\AssignmentStatus;
use App\Domains\Delivery\Models\DeliveryAssignment;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Exceptions\SubOrderTransitionRefused;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Vendors\Models\VendorStrike;
use Illuminate\Support\Facades\DB;

/**
 * What a seller may do with their sub-orders (SRS §4.3 My Orders, §7.1):
 * confirm → processing → packed → ready for pickup (the hand-off to the
 * carrier, recorded as `shipped`), or cancel before delivery with a reason,
 * which records a seller-score strike.
 */
class SellerFulfilment
{
    public function __construct(private SubOrderStateMachine $machine) {}

    /**
     * The one forward step a seller can take from a status, if any.
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

    /**
     * Button label for the next step.
     */
    public function stepLabel(SubOrderStatus $to): string
    {
        return match ($to) {
            SubOrderStatus::Confirmed => __('Confirm order'),
            SubOrderStatus::Processing => __('Start processing'),
            SubOrderStatus::Packed => __('Mark as packed'),
            SubOrderStatus::Shipped => __('Ready for pickup'),
            default => $to->value,
        };
    }

    /**
     * Sellers cancel before delivery (lifecycle §2.2) but not once the
     * parcel is out for delivery with an agent (QA-005): that parcel is
     * delivered, failed or returned by the delivery flow instead.
     */
    public function canCancel(SubOrder $subOrder): bool
    {
        return in_array(SubOrderStatus::Cancelled, $subOrder->status->allowedNext(), true)
            && $subOrder->status !== SubOrderStatus::OutForDelivery
            && ! DeliveryAssignment::query()->where('sub_order_id', $subOrder->id)->where('status', AssignmentStatus::PickedUp)->exists();
    }

    public function advance(SubOrder $subOrder, SubOrderStatus $to, int $actorUserId): SubOrder
    {
        if ($this->nextStep($subOrder->status) !== $to) {
            throw new SubOrderTransitionRefused(__('This order cannot move to :status from here.', ['status' => $to->value]));
        }

        return $this->machine->transition($subOrder, $to, ActorType::Vendor, $actorUserId);
    }

    /**
     * Seller cancellation: cancels through the state machine (stock back,
     * flash units back, warranty plans cancelled) and records a strike.
     */
    public function cancel(SubOrder $subOrder, string $reason, int $actorUserId): SubOrder
    {
        if (! $this->canCancel($subOrder)) {
            throw new SubOrderTransitionRefused(__('This order can no longer be cancelled.'));
        }

        return DB::connection($subOrder->getConnectionName())->transaction(function () use ($subOrder, $reason, $actorUserId): SubOrder {
            $this->machine->transition($subOrder, SubOrderStatus::Cancelled, ActorType::Vendor, $actorUserId, $reason);

            VendorStrike::query()->create([
                'vendor_id' => $subOrder->vendor_id,
                'sub_order_id' => $subOrder->id,
                'reason' => VendorStrike::REASON_SELLER_CANCELLATION,
                'note' => $reason,
                'points' => (int) config('nawy.vendors.cancellation_strike_points'),
                'actor_user_id' => $actorUserId,
            ]);

            return $subOrder;
        });
    }
}
