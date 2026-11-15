<?php

namespace App\Domains\Delivery\Support;

use App\Domains\Delivery\Enums\AssignmentStatus;
use App\Domains\Delivery\Enums\ShipmentStatus;
use App\Domains\Delivery\Models\Carrier;
use App\Domains\Delivery\Models\DeliveryAgent;
use App\Domains\Delivery\Models\Shipment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Shared queries of a shipping company's board (Shipping panel and the
 * supervisor ops app): parcels waiting for an agent, the company's agents
 * and the failed deliveries that need a decision.
 */
class CarrierBoard
{
    /**
     * Handed-over parcels without an open assignment yet.
     *
     * @return Builder<Shipment>
     */
    public function pickups(Carrier $carrier): Builder
    {
        return Shipment::query()->where('carrier_id', $carrier->id)
            ->whereIn('status', [ShipmentStatus::LabelCreated, ShipmentStatus::Rescheduled])
            ->whereDoesntHave('assignments', fn ($query) => $query->whereIn('status', AssignmentStatus::open()));
    }

    /**
     * Failed, rescheduled or returning parcels (the "failed deliveries" list).
     *
     * @return Builder<Shipment>
     */
    public function exceptions(Carrier $carrier): Builder
    {
        return Shipment::query()->where('carrier_id', $carrier->id)
            ->whereIn('status', [ShipmentStatus::Failed, ShipmentStatus::Rescheduled, ShipmentStatus::ReturningToOrigin]);
    }

    /**
     * Actions a supervisor may take on a failed-delivery row
     * (`reschedule` while attempts are left, `return`, `returned`).
     *
     * @return array<int, string>
     */
    public function exceptionActions(Shipment $shipment, int $maxAttempts): array
    {
        return array_values(array_filter([
            $shipment->status === ShipmentStatus::Failed && $shipment->attempts < $maxAttempts ? 'reschedule' : null,
            in_array($shipment->status, [ShipmentStatus::Failed, ShipmentStatus::Rescheduled], true) ? 'return' : null,
            $shipment->status === ShipmentStatus::ReturningToOrigin ? 'returned' : null,
        ]));
    }

    /**
     * @return Collection<int, int>
     */
    public function agentUserIds(Carrier $carrier): Collection
    {
        return DeliveryAgent::query()->where('carrier_id', $carrier->id)->pluck('user_id');
    }
}
