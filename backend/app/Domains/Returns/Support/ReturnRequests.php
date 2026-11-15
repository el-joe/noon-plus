<?php

namespace App\Domains\Returns\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Orders\Support\ReturnWindow;
use App\Domains\Orders\Support\SubOrderStateMachine;
use App\Domains\Refunds\Enums\RefundDestination;
use App\Domains\Refunds\Enums\RefundSource;
use App\Domains\Refunds\Support\Refunds;
use App\Domains\Returns\Enums\InspectionOutcome;
use App\Domains\Returns\Enums\ReturnMethod;
use App\Domains\Returns\Enums\ReturnReason;
use App\Domains\Returns\Enums\ReturnStatus;
use App\Domains\Returns\Models\ReturnItem;
use App\Domains\Returns\Models\ReturnRequest;
use App\Domains\Warranty\Enums\WarrantyPurchaseStatus;
use App\Domains\Warranty\Models\WarrantyPurchase;
use App\Shared\Money\MoneyFactory;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * The return machine (SRS §7.4, lifecycle catalogue §2.5).
 *
 * Request guards: the shipment is delivered, every item is inside its
 * return window (snapshotted per item), the quantity is still returnable
 * (other open or completed returns count) and custom / made-to-order items
 * only come back for a seller fault. Inspection decides the refund per
 * item and the restock (inventory ledger); the refund goes to the original
 * method or Nawy Credits through {@see Refunds} (once — key `return:{id}`).
 * When every unit of the shipment came back fully refunded, the sub-order
 * moves delivered → returned → refunded.
 */
class ReturnRequests
{
    /**
     * Product fulfilment modes treated as custom / made to order.
     */
    public const CUSTOM_MODES = ['made_to_order', 'custom'];

    public function __construct(
        private StateMachine $machine,
        private Refunds $refunds,
        private InventoryLedger $inventory,
        private SubOrderStateMachine $subOrders,
        private ReturnWindow $returnWindow,
        private CountryContext $countryContext,
        private MoneyFactory $money,
    ) {}

    /**
     * @param  array<int, array{order_item_id: int, quantity: int}>  $lines
     * @param  array<int, string>  $photos  stored paths
     */
    public function create(SubOrder $subOrder, int $customerUserId, array $lines, ReturnReason $reason, ReturnMethod $method, RefundDestination $destination, ?string $comment = null, array $photos = []): ReturnRequest
    {
        return DB::connection($subOrder->getConnectionName())->transaction(function () use ($subOrder, $customerUserId, $lines, $reason, $method, $destination, $comment, $photos): ReturnRequest {
            $locked = SubOrder::query()->lockForUpdate()->findOrFail($subOrder->id);
            $order = $locked->order()->firstOrFail();

            if ($order->customer_user_id !== $customerUserId) {
                throw new TransitionRefused(__('This shipment is not yours.'));
            }

            if ($locked->status !== SubOrderStatus::Delivered || $locked->delivered_at === null) {
                throw new TransitionRefused(__('Only delivered shipments can be returned.'));
            }

            $items = $locked->items()->with('listing.variant.product')->get()->keyBy('id');
            $default = $this->returnWindow->countryDefault($this->countryContext->current());
            $prepared = [];

            foreach ($lines as $line) {
                $item = $items->get((int) $line['order_item_id']) ?? throw new TransitionRefused(__('An item is not part of this shipment.'));
                $quantity = (int) $line['quantity'];
                $days = $item->return_window_days ?? $default;

                if ($locked->delivered_at->addDays($days)->isPast()) {
                    throw new TransitionRefused(__('The return window for :title closed on :date.', ['title' => $item->title, 'date' => $locked->delivered_at->addDays($days)->toDateString()]));
                }

                if (in_array($item->listing->variant->product->fulfilment_mode, self::CUSTOM_MODES, true) && ! $reason->isSellerFault()) {
                    throw new TransitionRefused(__(':title is made to order and can only be returned if it is faulty or not as ordered.', ['title' => $item->title]));
                }

                if ($quantity < 1 || $quantity > $this->returnableQuantity($item)) {
                    throw new TransitionRefused(__('You can return at most :count of :title.', ['count' => $this->returnableQuantity($item), 'title' => $item->title]));
                }

                $prepared[] = ['item' => $item, 'quantity' => $quantity];
            }

            if ($prepared === []) {
                throw new TransitionRefused(__('Choose at least one item to return.'));
            }

            $return = ReturnRequest::query()->create([
                'number' => 'PENDING-'.uniqid(),
                'order_id' => $order->id,
                'sub_order_id' => $locked->id,
                'vendor_id' => $locked->vendor_id,
                'customer_user_id' => $customerUserId,
                'status' => ReturnStatus::Requested,
                'reason' => $reason,
                'comment' => $comment,
                'method' => $method,
                'refund_destination' => $destination,
                'photos' => $photos,
            ]);
            $return->forceFill(['number' => 'RT-'.$locked->number.'-'.$return->id])->save();

            foreach ($prepared as ['item' => $item, 'quantity' => $quantity]) {
                $return->items()->create([
                    'order_item_id' => $item->id,
                    'quantity' => $quantity,
                    'paid_amount' => $this->money->toStorage($this->paidFor($locked, $item, $quantity, $order->currency)),
                    'warranty_amount' => $this->money->toStorage($this->money->of((string) $item->warranty_unit_price, $order->currency)->multipliedBy($quantity)),
                ]);
            }

            $this->machine->created($return, ActorType::Customer, $customerUserId, $reason->value);

            return $return->load('items');
        });
    }

    public function approve(ReturnRequest $return, ActorType $actorType, ?int $actorUserId = null): ReturnRequest
    {
        return $this->machine->move($return, ReturnStatus::Approved, $actorType, $actorUserId);
    }

    public function reject(ReturnRequest $return, ActorType $actorType, ?int $actorUserId, string $reason): ReturnRequest
    {
        return $this->machine->move($return, ReturnStatus::Rejected, $actorType, $actorUserId, $reason, ['rejection_reason' => mb_substr($reason, 0, 255)]);
    }

    public function cancel(ReturnRequest $return, int $customerUserId): ReturnRequest
    {
        return $this->machine->move($return, ReturnStatus::Cancelled, ActorType::Customer, $customerUserId, 'Cancelled by the customer');
    }

    /**
     * Logistics steps: awaiting_pickup, in_transit, received, inspecting.
     */
    public function advance(ReturnRequest $return, ReturnStatus $to, ActorType $actorType, ?int $actorUserId = null): ReturnRequest
    {
        if (! in_array($to, [ReturnStatus::AwaitingPickup, ReturnStatus::InTransit, ReturnStatus::Received, ReturnStatus::Inspecting], true)) {
            throw new TransitionRefused(__('Use approve, reject, cancel or inspect for this step.'));
        }

        if ($to === ReturnStatus::AwaitingPickup && $return->method !== ReturnMethod::Pickup) {
            throw new TransitionRefused(__('This return is dropped off by the customer.'));
        }

        return $this->machine->move($return, $to, $actorType, $actorUserId);
    }

    /**
     * Record the inspection and finish the return.
     *
     * @param  array<int, array{outcome: string, restock: bool, refund_amount?: string|null}>  $results  keyed by return item id
     */
    public function inspect(ReturnRequest $return, array $results, ActorType $actorType, ?int $actorUserId = null): ReturnRequest
    {
        if ($return->status === ReturnStatus::Received) {
            $this->machine->move($return, ReturnStatus::Inspecting, $actorType, $actorUserId);
        }

        if ($return->status !== ReturnStatus::Inspecting) {
            throw new TransitionRefused(__('The items must be received before they are inspected.'));
        }

        $order = $return->order()->firstOrFail();
        $currency = $order->currency;
        $zero = $this->money->zero($currency);

        $return = DB::connection($return->getConnectionName())->transaction(function () use ($return, $results, $actorType, $actorUserId, $currency, $zero): ReturnRequest {
            $refundTotal = $zero;
            $warrantyRefunded = $zero;
            $transitDamage = false;

            foreach ($return->items()->with('orderItem')->get() as $item) {
                $result = $results[$item->id] ?? throw new TransitionRefused(__('Every returned item needs an inspection result.'));
                $outcome = InspectionOutcome::tryFrom((string) $result['outcome']) ?? throw new TransitionRefused(__('Unknown inspection outcome.'));
                $paid = $this->money->of((string) $item->paid_amount, $currency);
                $warranty = $this->money->of((string) $item->warranty_amount, $currency);

                if ($outcome->refundsInFull()) {
                    $refund = $paid->plus($warranty);
                    $warrantyRefunded = $warrantyRefunded->plus($warranty);
                    $this->cancelWarranty($item);
                } else {
                    $refund = $this->money->of((string) ($result['refund_amount'] ?? '0'), $currency);

                    if ($refund->isNegative() || $refund->isGreaterThan($paid)) {
                        throw new TransitionRefused(__('A partial refund must be between 0 and :amount.', ['amount' => (string) $paid->getAmount()]));
                    }
                }

                $restock = (bool) ($result['restock'] ?? false);

                if ($restock) {
                    $this->inventory->move(Listing::query()->findOrFail($item->orderItem->listing_id), $item->quantity, 'return_restock', 'return_item', $item->id, $actorUserId);
                }

                $transitDamage = $transitDamage || $outcome === InspectionOutcome::TransitDamaged;
                $item->forceFill(['outcome' => $outcome, 'restock' => $restock, 'refund_amount' => $this->money->toStorage($refund)])->save();
                $refundTotal = $refundTotal->plus($refund);
            }

            $refundId = null;

            if ($refundTotal->isPositive()) {
                $refundId = $this->refunds->issue(
                    $return->order()->firstOrFail(), $return->subOrder()->firstOrFail(), $refundTotal, RefundSource::Return, $return->id,
                    $return->refund_destination, "return:{$return->id}", $actorType, $actorUserId, "Return {$return->number}", $warrantyRefunded,
                )->id;
            }

            return $this->machine->move($return, ReturnStatus::Completed, $actorType, $actorUserId, null, [
                'refund_total' => $this->money->toStorage($refundTotal),
                'refund_id' => $refundId,
                'carrier_investigation' => $transitDamage,
                'completed_at' => now(),
            ]);
        });

        $this->closeShipmentIfFullyReturned($return->subOrder()->firstOrFail(), $actorType, $actorUserId);

        return $return->refresh();
    }

    /**
     * Units of an item that can still be returned (open and completed returns count).
     */
    public function returnableQuantity(OrderItem $item): int
    {
        $taken = (int) ReturnItem::query()
            ->where('order_item_id', $item->id)
            ->whereHas('returnRequest', fn ($query) => $query->whereNotIn('status', [ReturnStatus::Rejected, ReturnStatus::Cancelled]))
            ->sum('quantity');

        return max(0, $item->quantity - $taken);
    }

    /**
     * What the customer paid for `quantity` units: the line after its share
     * of the shipment's discounts, pro rata.
     */
    private function paidFor(SubOrder $subOrder, OrderItem $item, int $quantity, string $currency): Money
    {
        $line = $this->money->of((string) $item->line_total, $currency);
        $subtotal = $this->money->of((string) $subOrder->subtotal, $currency);
        $discount = $this->money->of((string) $subOrder->discount_total, $currency);
        $share = $subtotal->isZero() ? $discount : $discount->multipliedBy((string) $line->getAmount(), RoundingMode::HalfUp)->dividedBy((string) $subtotal->getAmount(), RoundingMode::HalfUp);

        return $line->minus($share)->multipliedBy($quantity, RoundingMode::HalfUp)->dividedBy($item->quantity, RoundingMode::HalfUp);
    }

    private function cancelWarranty(ReturnItem $item): void
    {
        $purchase = WarrantyPurchase::query()->where('order_item_id', $item->order_item_id)->where('status', WarrantyPurchaseStatus::Active)->first();

        if ($purchase === null) {
            return;
        }

        $left = $purchase->quantity - $item->quantity;
        $purchase->forceFill($left <= 0 ? ['status' => WarrantyPurchaseStatus::Cancelled] : ['quantity' => $left])->save();
    }

    private function closeShipmentIfFullyReturned(SubOrder $subOrder, ActorType $actorType, ?int $actorUserId): void
    {
        if ($subOrder->status !== SubOrderStatus::Delivered) {
            return;
        }

        foreach ($subOrder->items()->get() as $item) {
            $refundedUnits = (int) ReturnItem::query()
                ->where('order_item_id', $item->id)
                ->whereNotNull('outcome')
                ->where('outcome', '!=', InspectionOutcome::CustomerDamaged)
                ->sum('quantity');

            if ($refundedUnits < $item->quantity) {
                return;
            }
        }

        $this->subOrders->transition($subOrder, SubOrderStatus::Returned, $actorType === ActorType::Customer ? ActorType::System : $actorType, $actorUserId, 'All items returned');
        $this->subOrders->transition($subOrder->refresh(), SubOrderStatus::Refunded, ActorType::System, null, 'Return refunded');
    }
}
