<?php

namespace App\Domains\Orders\Http\Resources;

use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Delivery\Enums\AssignmentStatus;
use App\Domains\Delivery\Models\DeliveryAssignment;
use App\Domains\Listings\Models\Listing;
use App\Domains\Orders\Models\Order;
use App\Domains\Payments\Http\Resources\PaymentResource;
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
        $images = $this->lineImages();

        return [
            'number' => $this->number,
            'status' => $this->status->value,
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'payment_due_at' => $this->payment_due_at?->toIso8601String(),
            'payment' => $this->relationLoaded('payments') && $this->payments->isNotEmpty() ? new PaymentResource($this->payments->last()) : null,
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
                // ADR 15: the customer's copy of the code of the open delivery attempt.
                'delivery_code' => DeliveryAssignment::query()->where('sub_order_id', $subOrder->id)->whereIn('status', AssignmentStatus::open())->latest('id')->value('delivery_code'),
                'items' => $subOrder->items->map(fn ($item): array => [
                    'id' => $item->id,
                    'sku' => $item->sku,
                    'title' => $item->title,
                    'product_slug' => $images[$item->listing_id]['slug'] ?? null,
                    'image' => $images[$item->listing_id]['image'] ?? null,
                    'quantity' => $item->quantity,
                    'unit_price' => MoneyResource::make((string) $item->unit_price),
                    'line_total' => MoneyResource::make((string) $item->line_total),
                    'warranty_total' => MoneyResource::make((string) $item->warranty_total),
                ])->all(),
            ])->all(),
            'shipping_address' => $this->shipping_address,
        ];
    }

    /**
     * Product slug and main image per listing of the order's lines (one query).
     *
     * @return array<int, array{slug: string, image: array<string, mixed>|null}>
     */
    private function lineImages(): array
    {
        $listingIds = $this->subOrders->flatMap(fn ($subOrder) => $subOrder->items->pluck('listing_id'))->filter()->unique()->values();

        if ($listingIds->isEmpty()) {
            return [];
        }

        return Listing::query()->whereIn('id', $listingIds)->with('variant.product.media')->get()
            ->mapWithKeys(fn (Listing $listing): array => [$listing->id => [
                'slug' => $listing->variant->product->slug,
                'image' => $listing->variant->product->primaryImage($listing->product_variant_id)?->toPublicArray(),
            ]])->all();
    }
}
