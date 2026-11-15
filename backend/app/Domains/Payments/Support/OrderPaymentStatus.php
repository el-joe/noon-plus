<?php

namespace App\Domains\Payments\Support;

use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;

/**
 * Derives `orders.payment_status` from the payment attempts (FR-CHK-3):
 * the successful attempt decides (authorized, captured, partially_refunded,
 * refunded); without one, the newest attempt does (pending or failed —
 * the order stays on hold for a retry). An unpaid order that was cancelled
 * reads `expired`.
 */
class OrderPaymentStatus
{
    public function derive(Order $order): string
    {
        $payments = $order->payments()->get();
        $settled = $payments->last(fn (Payment $payment): bool => $payment->status->isSuccessful());

        if ($settled !== null) {
            return $settled->status->value;
        }

        if ($order->status === OrderStatus::Cancelled) {
            return PaymentStatus::Expired->value;
        }

        return $payments->last()?->status->value ?? PaymentStatus::Pending->value;
    }

    public function sync(Order $order): Order
    {
        $order->forceFill(['payment_status' => $this->derive($order)])->save();

        return $order;
    }
}
