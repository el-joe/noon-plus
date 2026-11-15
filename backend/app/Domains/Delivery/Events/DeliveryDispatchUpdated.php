<?php

namespace App\Domains\Delivery\Events;

use App\Domains\Delivery\Models\DeliveryAssignment;
use App\Domains\Delivery\Models\Shipment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Live dispatch update for the ops screens (dispatch map, supervisors) on the
 * private channel `country.{code}.ops` (routes/channels.php). Carries ids
 * and statuses only — never the delivery code or the customer's address.
 */
class DeliveryDispatchUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public string $countryCode, public string $event, public array $payload) {}

    public static function forAssignment(string $countryCode, string $event, DeliveryAssignment $assignment): self
    {
        return new self($countryCode, $event, [
            'assignment' => $assignment->number,
            'status' => $assignment->status->value,
            'shipment_id' => $assignment->shipment_id,
            'sub_order_id' => $assignment->sub_order_id,
            'agent_id' => $assignment->delivery_agent_id,
            'carrier_id' => $assignment->carrier_id,
            'sla_due_at' => $assignment->sla_due_at?->toIso8601String(),
        ]);
    }

    public static function forShipment(string $countryCode, string $event, Shipment $shipment): self
    {
        return new self($countryCode, $event, [
            'shipment' => $shipment->number,
            'status' => $shipment->status->value,
            'shipment_id' => $shipment->id,
            'sub_order_id' => $shipment->sub_order_id,
            'carrier_id' => $shipment->carrier_id,
            'attempts' => $shipment->attempts,
        ]);
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("country.{$this->countryCode}.ops")];
    }

    public function broadcastAs(): string
    {
        return 'delivery.dispatch';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['event' => $this->event] + $this->payload;
    }
}
