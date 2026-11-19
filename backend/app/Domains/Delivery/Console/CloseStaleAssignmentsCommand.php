<?php

namespace App\Domains\Delivery\Console;

use App\Domains\Countries\Models\Country;
use App\Domains\Delivery\Enums\AssignmentStatus;
use App\Domains\Delivery\Enums\ShipmentStatus;
use App\Domains\Delivery\Models\DeliveryAssignment;
use App\Domains\Delivery\Models\Shipment;
use App\Domains\Delivery\Support\DeliveryDispatch;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\SubOrder;
use App\Shared\Scheduling\ForEachCountry;
use App\Shared\StateMachine\StateTransition;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off clean-up for RT-001: assignments opened before the QA-005 fix
 * can still be open on a sub-order that was cancelled (or returned /
 * refunded). They are closed the same way a new cancel closes them.
 *
 * With `--repair-delivered`, assignments that were confirmed delivered
 * after their sub-order had already been cancelled (the dead guard) are
 * put back: assignment → cancelled, shipment → returning to origin, each
 * with a history row naming the repair. A delivery confirmed before an
 * admin force-cancel is left alone.
 */
#[Signature('nawy:delivery:close-stale-assignments {--repair-delivered : Also undo deliveries confirmed after the order was cancelled} {--dry-run : List what would change without changing it}')]
#[Description('Close delivery assignments still open on cancelled, returned or refunded sub-orders, in every country (RT-001)')]
class CloseStaleAssignmentsCommand extends Command
{
    private const REPAIR_REASON = 'Repair RT-001: delivered after the order was cancelled';

    public function handle(ForEachCountry $forEachCountry, DeliveryDispatch $dispatch): int
    {
        $forEachCountry->run('close-stale-assignments', function (Country $country) use ($dispatch): void {
            $closed = $this->closeOpen($dispatch);
            $repaired = $this->option('repair-delivered') ? $this->repairDelivered() : [];

            $this->components->info("{$country->code}: closed ".count($closed).', repaired '.count($repaired));

            foreach ([...$closed, ...$repaired] as $number) {
                $this->line("  {$number}");
            }
        });

        return self::SUCCESS;
    }

    /**
     * @return list<string> assignment numbers
     */
    private function closeOpen(DeliveryDispatch $dispatch): array
    {
        $stale = DeliveryAssignment::query()
            ->whereIn('status', AssignmentStatus::open())
            ->whereHas('subOrder', fn ($query) => $query->whereIn('status', [SubOrderStatus::Cancelled, SubOrderStatus::Returned, SubOrderStatus::Refunded]))
            ->with('subOrder')
            ->get();

        if (! $this->option('dry-run')) {
            foreach ($stale->pluck('subOrder')->unique('id') as $subOrder) {
                $dispatch->closeForCancelledSubOrder($subOrder, ActorType::System);
            }
        }

        return $stale->pluck('number')->values()->all();
    }

    /**
     * @return list<string> assignment numbers
     */
    private function repairDelivered(): array
    {
        $repaired = [];

        $candidates = DeliveryAssignment::query()
            ->where('status', AssignmentStatus::Delivered)
            ->whereHas('subOrder', fn ($query) => $query->where('status', SubOrderStatus::Cancelled))
            ->with(['subOrder', 'shipment'])
            ->get();

        foreach ($candidates as $assignment) {
            if (! $this->deliveredAfterCancel($assignment, $assignment->subOrder)) {
                continue;
            }

            $repaired[] = $assignment->number;

            if ($this->option('dry-run')) {
                continue;
            }

            DB::connection($assignment->getConnectionName())->transaction(function () use ($assignment): void {
                $this->forceMove($assignment, AssignmentStatus::Cancelled);
                $shipment = $assignment->shipment;

                if ($shipment !== null && $shipment->status === ShipmentStatus::Delivered) {
                    $this->forceMove($shipment, ShipmentStatus::ReturningToOrigin, ['delivered_at' => null]);
                }
            });
        }

        return $repaired;
    }

    private function deliveredAfterCancel(DeliveryAssignment $assignment, SubOrder $subOrder): bool
    {
        $cancelledAt = $subOrder->statusHistory()
            ->where('to_status', SubOrderStatus::Cancelled)
            ->latest('id')
            ->value('created_at');

        return $cancelledAt !== null && $assignment->delivered_at !== null && $assignment->delivered_at->greaterThan($cancelledAt);
    }

    /**
     * A repair move outside the lifecycle (delivered has no next state), with
     * its own history row so the change is visible.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function forceMove(DeliveryAssignment|Shipment $subject, AssignmentStatus|ShipmentStatus $to, array $attributes = []): void
    {
        $from = $subject->status;
        $subject->forceFill(['status' => $to] + $attributes)->save();

        StateTransition::query()->create([
            'subject_type' => $subject->transitionSubject(),
            'subject_id' => $subject->getKey(),
            'from_status' => $from->value,
            'to_status' => $to->value,
            'actor_type' => ActorType::System,
            'actor_user_id' => null,
            'reason' => self::REPAIR_REASON,
            'meta' => ['repair' => 'RT-001'],
        ]);
    }
}
