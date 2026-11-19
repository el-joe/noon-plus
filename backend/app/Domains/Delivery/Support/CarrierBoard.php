<?php

namespace App\Domains\Delivery\Support;

use App\Domains\Delivery\Enums\AssignmentStatus;
use App\Domains\Delivery\Enums\ShipmentStatus;
use App\Domains\Delivery\Models\Carrier;
use App\Domains\Delivery\Models\DeliveryAgent;
use App\Domains\Delivery\Models\DeliveryAssignment;
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

    /**
     * QC-062 (Figma 106:15992): delivery SLA per shipping zone over the last
     * `days` days — attempts delivered, on time (delivered by `sla_due_at`),
     * late, failed, and open attempts already past their SLA.
     *
     * @return Collection<int, array{zone: string, delivered: int, on_time: int, late: int, failed: int, open_overdue: int, on_time_percent: int|null}>
     */
    public function slaByZone(Carrier $carrier, int $days = 30): Collection
    {
        $since = now()->subDays($days);
        $rows = DeliveryAssignment::query()
            ->where('delivery_assignments.carrier_id', $carrier->id)
            ->where('delivery_assignments.created_at', '>=', $since)
            ->join('sub_orders', 'sub_orders.id', '=', 'delivery_assignments.sub_order_id')
            ->leftJoin('shipping_zones', 'shipping_zones.id', '=', 'sub_orders.shipping_zone_id')
            ->groupBy('shipping_zones.id', 'shipping_zones.name')
            ->selectRaw('shipping_zones.name as zone')
            ->selectRaw("sum(case when delivery_assignments.status = 'delivered' then 1 else 0 end) as delivered")
            ->selectRaw("sum(case when delivery_assignments.status = 'delivered' and (delivery_assignments.sla_due_at is null or delivery_assignments.delivered_at <= delivery_assignments.sla_due_at) then 1 else 0 end) as on_time")
            ->selectRaw("sum(case when delivery_assignments.status = 'failed' then 1 else 0 end) as failed")
            ->selectRaw('sum(case when delivery_assignments.status in (?, ?, ?) and delivery_assignments.sla_due_at < ? then 1 else 0 end) as open_overdue',
                [...array_map(fn (AssignmentStatus $status): string => $status->value, AssignmentStatus::open()), now()])
            ->orderBy('shipping_zones.name')
            ->get();

        return $rows->map(fn ($row): array => [
            'zone' => $row->zone ?? __('No zone'),
            'delivered' => (int) $row->delivered,
            'on_time' => (int) $row->on_time,
            'late' => (int) $row->delivered - (int) $row->on_time,
            'failed' => (int) $row->failed,
            'open_overdue' => (int) $row->open_overdue,
            'on_time_percent' => (int) $row->delivered === 0 ? null : intdiv((int) $row->on_time * 100, (int) $row->delivered),
        ])->values();
    }
}
