<?php

namespace App\Domains\Orders\Support;

use App\Domains\Countries\Models\Country;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\SubOrder;
use Illuminate\Database\Eloquent\Collection;

/**
 * AutoCompleteOrdersJob (SRS §7.1, §17): a delivered sub-order becomes
 * completed once its return window has passed — `delivered_at` plus the
 * longest window among its items (snapshotted per item at placement, else
 * the country default). Completion unlocks payouts and conversion approval.
 */
class AutoCompleteDeliveredOrders
{
    public function __construct(
        private SubOrderStateMachine $machine,
        private ReturnWindow $returnWindow,
    ) {}

    /**
     * Complete the current country's eligible sub-orders; returns how many.
     */
    public function run(Country $country): int
    {
        $default = $this->returnWindow->countryDefault($country);
        $now = now();
        $completed = 0;

        SubOrder::query()
            ->where('status', SubOrderStatus::Delivered)
            ->whereNotNull('delivered_at')
            ->where('delivered_at', '<=', $now)
            ->with('items:id,sub_order_id,return_window_days')
            ->chunkById(200, function (Collection $subOrders) use ($default, $now, &$completed): void {
                foreach ($subOrders as $subOrder) {
                    $days = (int) $subOrder->items->max(fn ($item): int => $item->return_window_days ?? $default);

                    if ($subOrder->delivered_at->addDays($days)->lessThanOrEqualTo($now)) {
                        $this->machine->transition($subOrder, SubOrderStatus::Completed, ActorType::System, null, "Return window of {$days} days passed");
                        $completed++;
                    }
                }
            });

        return $completed;
    }
}
