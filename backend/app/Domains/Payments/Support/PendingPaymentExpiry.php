<?php

namespace App\Domains\Payments\Support;

use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Exceptions\SubOrderTransitionRefused;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Support\SubOrderStateMachine;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Support\Data\GatewayOutcome;

/**
 * ExpirePendingPaymentsJob (every 5 min, SRS FR-CHK-3): an unpaid
 * card/BNPL order whose hold window (`payment_due_at`) has passed is
 * cancelled by the system — pending attempts are checked with the gateway
 * one last time, then expired; every sub-order is cancelled through the
 * sub-order state machine, which puts the stock back. COD orders never
 * expire here.
 */
class PendingPaymentExpiry
{
    public function __construct(
        private PaymentAttempts $attempts,
        private SubOrderStateMachine $subOrders,
        private OrderPaymentStatus $orderPaymentStatus,
    ) {}

    /**
     * @return int orders cancelled
     */
    public function run(): int
    {
        $cancelled = 0;

        Order::query()
            ->where('status', OrderStatus::Placed)
            ->where('payment_method', '!=', PaymentMethod::Cod->value)
            ->whereIn('payment_status', [PaymentStatus::Pending->value, PaymentStatus::Failed->value])
            ->where('payment_due_at', '<=', now())
            ->orderBy('id')
            ->each(function (Order $order) use (&$cancelled): void {
                if ($this->expire($order)) {
                    $cancelled++;
                }
            });

        return $cancelled;
    }

    private function expire(Order $order): bool
    {
        foreach ($order->payments()->where('status', PaymentStatus::Pending)->get() as $payment) {
            $this->attempts->refresh($payment, source: 'expiry');
        }

        if (PaymentStatus::from($this->orderPaymentStatus->derive($order))->isSuccessful()) {
            $this->orderPaymentStatus->sync($order);

            return false;
        }

        foreach ($order->payments()->where('status', PaymentStatus::Pending)->get() as $payment) {
            $this->attempts->applyOutcome($payment, new GatewayOutcome(PaymentStatus::Expired, failureCode: 'expired', failureMessage: __('The time to pay for this order is over.')), 'expiry');
        }

        foreach ($order->subOrders()->get() as $subOrder) {
            if ($subOrder->status === SubOrderStatus::Cancelled) {
                continue;
            }

            try {
                $this->subOrders->transition($subOrder, SubOrderStatus::Cancelled, ActorType::System, reason: 'Payment not completed in time');
            } catch (SubOrderTransitionRefused) {
                continue;
            }
        }

        $this->orderPaymentStatus->sync($order->refresh());

        return true;
    }
}
