<?php

namespace App\Domains\Refunds\Support;

use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Refunds\Enums\RefundDestination;
use App\Domains\Refunds\Enums\RefundSource;
use App\Domains\Refunds\Models\Refund;
use App\Shared\Money\MoneyFactory;
use Brick\Money\Money;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cancelling a paid sub-order starts its refund (SRS §7.3): the sub-order
 * total plus its warranty plans, to the original method, once (key
 * `cancel:{sub-order}`). Unpaid orders (pending card, COD not collected)
 * have nothing to refund. A gateway failure leaves a failed refund for
 * finance to retry and never blocks the cancellation.
 */
class CancellationRefunds
{
    public function __construct(private Refunds $refunds, private MoneyFactory $money) {}

    public function forCancelledSubOrder(SubOrder $subOrder, ActorType $actorType, ?int $actorUserId = null): ?Refund
    {
        $order = $subOrder->order()->firstOrFail();
        $warranty = $this->money->of((string) $subOrder->warranty_total, $order->currency);
        $amount = Money::min(
            $this->money->of((string) $subOrder->total, $order->currency)->plus($warranty),
            $this->refunds->refundable($order),
        );

        if (! $amount->isPositive()) {
            return null;
        }

        try {
            return $this->refunds->issue($order, $subOrder, $amount, RefundSource::Cancellation, $subOrder->id, RefundDestination::Original,
                "cancel:{$subOrder->id}", $actorType, $actorUserId, 'Sub-order cancelled', Money::min($warranty, $amount));
        } catch (Throwable $exception) {
            Log::error('Cancellation refund failed', ['sub_order' => $subOrder->number, 'error' => $exception->getMessage()]);
            report($exception);

            return null;
        }
    }
}
