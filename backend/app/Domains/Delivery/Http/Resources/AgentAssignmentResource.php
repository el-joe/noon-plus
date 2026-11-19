<?php

namespace App\Domains\Delivery\Http\Resources;

use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Delivery\Models\DeliveryAssignment;
use App\Domains\Delivery\Support\AgentAssignments;
use App\Domains\Delivery\Support\DeliverySettings;
use App\Domains\Delivery\Support\ParcelBarcode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An assignment as the delivery app sees it (ADR 15): the delivery code is
 * shown to the agent; the customer's name, phone and address only while
 * the assignment is open (role-permission matrix T8).
 *
 * @mixin DeliveryAssignment
 */
class AgentAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $subOrder = $this->subOrder;
        $order = $subOrder->order;
        $open = $this->status->isOpen();
        $settings = app(DeliverySettings::class);

        return [
            'number' => $this->number,
            'status' => $this->status->value,
            'attempt' => $this->attempt_no,
            'delivery_code' => $open ? $this->delivery_code : null,
            'shipment_number' => $subOrder->number,
            // Parcel label barcode payload (ParcelBarcode): `<AWB>.<mod-43 check>`.
            'barcode' => $this->shipment === null ? null : ParcelBarcode::payload($this->shipment),
            'order_number' => $order->number,
            'payment_method' => $order->payment_method,
            'cod_amount' => $this->cod_amount === null ? null : MoneyResource::make((string) $this->cod_amount),
            'pickup' => ['seller' => $subOrder->vendor?->store_name],
            'customer' => $open ? [
                'name' => $order->shipping_address['name'] ?? null,
                'phone' => $order->shipping_address['phone'] ?? null,
                'address' => collect($order->shipping_address)->except(['name', 'phone', 'address_id'])->all(),
            ] : null,
            'items' => $subOrder->items->map(fn ($item): array => ['title' => $item->title, 'quantity' => $item->quantity])->all(),
            'proof' => [
                'required' => $open && app(AgentAssignments::class)->needsProof($this->resource),
                'type' => $settings->proofType(),
                'has_photo' => $this->proof_photo_path !== null,
                'has_signature' => $this->signature_path !== null,
            ],
            'failure_reasons' => $settings->failureReasons(),
            'failure_reason' => $this->failure_reason,
            'sla_due_at' => $this->sla_due_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'picked_up_at' => $this->picked_up_at?->toIso8601String(),
            'arrived_at' => $this->arrived_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
        ];
    }
}
