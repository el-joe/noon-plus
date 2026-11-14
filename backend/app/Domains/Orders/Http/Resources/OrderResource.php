<?php

namespace App\Domains\Orders\Http\Resources;

use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Orders\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'number' => $this->number,
            'status' => $this->status->value,
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'placed_at' => $this->placed_at->toIso8601String(),
            'totals' => [
                'subtotal' => MoneyResource::make((string) $this->subtotal),
                'discount' => MoneyResource::make((string) $this->discount_total),
                'shipping' => MoneyResource::make((string) $this->shipping_total),
                'cod_fee' => MoneyResource::make((string) $this->cod_fee),
                'warranty' => MoneyResource::make((string) $this->warranty_total),
                'total' => MoneyResource::make((string) $this->grand_total),
                'tax_included' => MoneyResource::make((string) $this->tax_included),
            ],
            'shipments' => $this->subOrders->map(fn ($subOrder): array => [
                'number' => $subOrder->number,
                'status' => $subOrder->status->value,
                'seller' => $subOrder->vendor?->store_name,
                'total' => MoneyResource::make((string) $subOrder->total),
                'shipping' => MoneyResource::make((string) $subOrder->shipping_total),
                'method' => $subOrder->shipping_method,
                'delivery' => [
                    'from' => $subOrder->estimated_delivery_from?->toDateString(),
                    'by' => $subOrder->estimated_delivery_by?->toDateString(),
                ],
                'items' => $subOrder->items->map(fn ($item): array => [
                    'sku' => $item->sku,
                    'title' => $item->title,
                    'quantity' => $item->quantity,
                    'unit_price' => MoneyResource::make((string) $item->unit_price),
                    'line_total' => MoneyResource::make((string) $item->line_total),
                    'warranty_total' => MoneyResource::make((string) $item->warranty_total),
                ])->all(),
            ])->all(),
            'shipping_address' => $this->shipping_address,
        ];
    }
}
