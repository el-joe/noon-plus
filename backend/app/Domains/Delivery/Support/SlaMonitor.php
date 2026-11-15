<?php

namespace App\Domains\Delivery\Support;

use App\Domains\Delivery\Enums\AssignmentStatus;
use App\Domains\Delivery\Models\DeliveryAssignment;

/**
 * CheckSlaBreachJob (SRS §8, §17, every 15 minutes): open assignments past
 * their SLA are flagged once and broadcast to the ops channel; the flag
 * feeds the scorecards.
 */
class SlaMonitor
{
    public function __construct(private DeliveryDispatch $dispatch) {}

    public function check(): int
    {
        $flagged = 0;

        DeliveryAssignment::query()
            ->whereIn('status', AssignmentStatus::open())
            ->whereNull('sla_breached_at')
            ->where('sla_due_at', '<', now())
            ->each(function (DeliveryAssignment $assignment) use (&$flagged): void {
                $assignment->forceFill(['sla_breached_at' => now()])->save();
                $this->dispatch->broadcastAssignment('sla_breached', $assignment);
                $flagged++;
            });

        return $flagged;
    }
}
