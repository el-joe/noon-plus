<?php

namespace App\Domains\Returns\Http\Resources;

use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Returns\Models\ReturnRequest;
use App\Domains\Returns\Support\ReturnPhotos;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReturnRequest
 */
class ReturnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'number' => $this->number,
            'shipment' => $this->subOrder?->number,
            'status' => $this->status->value,
            'reason' => $this->reason->value,
            'comment' => $this->comment,
            'method' => $this->method->value,
            'refund_destination' => $this->refund_destination->value,
            'rejection_reason' => $this->rejection_reason,
            'photos' => app(ReturnPhotos::class)->urls($this->resource),
            'items' => $this->items->map(fn ($item): array => [
                'order_item_id' => $item->order_item_id,
                'title' => $item->orderItem?->title,
                'quantity' => $item->quantity,
                'paid' => MoneyResource::make((string) $item->paid_amount),
                'outcome' => $item->outcome?->value,
                'refund' => MoneyResource::make($item->refund_amount === null ? null : (string) $item->refund_amount),
            ])->all(),
            'refund' => $this->refund === null ? null : [
                'number' => $this->refund->number,
                'status' => $this->refund->status->value,
                'destination' => $this->refund->destination->value,
                'amount' => MoneyResource::make((string) $this->refund->amount),
            ],
            'created_at' => $this->created_at->toIso8601String(),
            'timeline' => $this->transitions->map(fn ($step): array => ['status' => $step->to_status, 'at' => $step->created_at->toIso8601String()])->all(),
        ];
    }
}
