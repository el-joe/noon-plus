<?php

namespace App\Domains\Disputes\Support;

use App\Domains\Disputes\Enums\DisputeReason;
use App\Domains\Disputes\Enums\DisputeResolution;
use App\Domains\Disputes\Enums\DisputeStatus;
use App\Domains\Disputes\Models\Dispute;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Orders\Support\OrderStatusRollup;
use App\Domains\Panels\Listeners\NotifySellerOfStateChange;
use App\Domains\Refunds\Enums\RefundDestination;
use App\Domains\Refunds\Enums\RefundSource;
use App\Domains\Refunds\Support\Refunds;
use App\Shared\Money\MoneyFactory;
use App\Shared\Notifications\Email\Listeners\CommerceLifecycleEmails;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * The dispute machine (SRS §7.6, lifecycle catalogue §2.7): the customer
 * opens one per shipment (no second open dispute), the seller's first
 * reply moves it to seller_responded, an admin reviews, may escalate and
 * resolves — favor_customer / split refund the customer through
 * {@see Refunds} (key `dispute:{id}`) — then closes it.
 */
class Disputes
{
    public function __construct(private StateMachine $machine, private Refunds $refunds, private MoneyFactory $money) {}

    public function open(SubOrder $subOrder, int $customerUserId, DisputeReason $reason, string $message): Dispute
    {
        return DB::connection($subOrder->getConnectionName())->transaction(function () use ($subOrder, $customerUserId, $reason, $message): Dispute {
            $locked = SubOrder::query()->lockForUpdate()->findOrFail($subOrder->id);
            $order = $locked->order()->firstOrFail();

            if ($order->customer_user_id !== $customerUserId) {
                throw new TransitionRefused(__('This shipment is not yours.'));
            }

            if (! in_array($locked->status, [SubOrderStatus::Shipped, SubOrderStatus::OutForDelivery, SubOrderStatus::Delivered, SubOrderStatus::Completed], true)) {
                throw new TransitionRefused(__('A dispute can be opened once the shipment has been sent.'));
            }

            if (Dispute::query()->where('sub_order_id', $locked->id)->whereIn('status', DisputeStatus::openValues())->exists()) {
                throw new TransitionRefused(__('There is already an open dispute for this shipment.'));
            }

            $dispute = Dispute::query()->create([
                'number' => 'PENDING-'.uniqid(),
                'order_id' => $order->id,
                'sub_order_id' => $locked->id,
                'vendor_id' => $locked->vendor_id,
                'customer_user_id' => $customerUserId,
                'status' => DisputeStatus::Open,
                'reason' => $reason,
            ]);
            $dispute->forceFill(['number' => 'DS-'.$locked->number.'-'.$dispute->id])->save();
            $dispute->messages()->create(['author_type' => ActorType::Customer, 'author_user_id' => $customerUserId, 'body' => $message]);
            $this->machine->created($dispute, ActorType::Customer, $customerUserId, $reason->value);
            NotifySellerOfStateChange::notifyVendor($locked->vendor_id, 'dispute.opened', __('Dispute :number opened', ['number' => $dispute->number]), $message, '/disputes/'.$dispute->id);
            app(OrderStatusRollup::class)->sync($order, 'Dispute '.$dispute->number.' opened');
            app(CommerceLifecycleEmails::class)->disputeOpened($dispute, $message);

            return $dispute;
        });
    }

    /**
     * Add to the thread; the seller's first reply moves an open dispute to
     * seller_responded. Closed disputes take no messages.
     */
    public function message(Dispute $dispute, ActorType $author, ?int $authorUserId, string $body): Dispute
    {
        if ($dispute->status === DisputeStatus::Closed) {
            throw new TransitionRefused(__('This dispute is closed.'));
        }

        $created = $dispute->messages()->create(['author_type' => $author, 'author_user_id' => $authorUserId, 'body' => $body]);
        app(CommerceLifecycleEmails::class)->disputeMessage($dispute, $created);

        if ($author === ActorType::Vendor && $dispute->status === DisputeStatus::Open) {
            $this->machine->move($dispute, DisputeStatus::SellerResponded, ActorType::Vendor, $authorUserId);
        }

        return $dispute->refresh();
    }

    public function review(Dispute $dispute, int $adminUserId): Dispute
    {
        return $this->machine->move($dispute, DisputeStatus::UnderReview, ActorType::Admin, $adminUserId);
    }

    public function escalate(Dispute $dispute, int $adminUserId, string $reason): Dispute
    {
        return $this->machine->move($dispute, DisputeStatus::Escalated, ActorType::Admin, $adminUserId, $reason);
    }

    /**
     * Decide. A customer refund needs an amount for `split`; `favor_customer`
     * defaults to what the shipment cost (capped by what is still refundable).
     */
    public function resolve(Dispute $dispute, int $adminUserId, DisputeResolution $resolution, ?Money $refundAmount = null, ?string $note = null): Dispute
    {
        $refundId = null;

        if ($resolution->refundsCustomer()) {
            $order = $dispute->order()->firstOrFail();
            $subOrder = $dispute->subOrder()->firstOrFail();
            $amount = $refundAmount ?? ($resolution === DisputeResolution::FavorCustomer
                ? Money::min($this->money->of((string) $subOrder->total, $order->currency)->plus($this->money->of((string) $subOrder->warranty_total, $order->currency)), $this->refunds->refundable($order))
                : throw new TransitionRefused(__('A split needs the refund amount.')));

            if (! in_array($dispute->status, [DisputeStatus::UnderReview, DisputeStatus::Escalated], true)) {
                throw new TransitionRefused(__('Review the dispute before resolving it.'));
            }

            $refundId = $this->refunds->issue($order, $subOrder, $amount, RefundSource::Dispute, $dispute->id, RefundDestination::Original,
                "dispute:{$dispute->id}", ActorType::Admin, $adminUserId, "Dispute {$dispute->number}")->id;
            $refundAmount = $amount;
        }

        $resolved = $this->machine->move($dispute, DisputeStatus::Resolved, ActorType::Admin, $adminUserId, $note ?? $resolution->value, [
            'resolution' => $resolution,
            'refund_amount' => $refundAmount === null ? null : $this->money->toStorage($refundAmount),
            'refund_id' => $refundId,
            'resolved_at' => now(),
        ]);
        $this->syncOrder($resolved, 'Dispute '.$resolved->number.' resolved');

        return $resolved;
    }

    public function close(Dispute $dispute, ActorType $actorType, ?int $actorUserId = null): Dispute
    {
        $closed = $this->machine->move($dispute, DisputeStatus::Closed, $actorType, $actorUserId);
        $this->syncOrder($closed, 'Dispute '.$closed->number.' closed');

        return $closed;
    }

    /**
     * QC-013: the order's `disputed` overlay follows its open disputes.
     */
    private function syncOrder(Dispute $dispute, string $reason): void
    {
        app(OrderStatusRollup::class)->sync(Order::query()->findOrFail($dispute->order_id), $reason);
    }
}
