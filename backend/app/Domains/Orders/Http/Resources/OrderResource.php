<?php

namespace App\Domains\Orders\Http\Resources;

use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Enums\AssignmentStatus;
use App\Domains\Delivery\Models\DeliveryAssignment;
use App\Domains\Listings\Models\Listing;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Orders\Support\InvoicePdf;
use App\Domains\Orders\Support\ReturnWindow;
use App\Domains\Payments\Http\Resources\PaymentResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

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
        $invoiceReady = $this->invoiceReady();
        $returnDays = app(ReturnWindow::class)->countryDefault(app(CountryContext::class)->current());

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
                // Owner decision 2026-11-19: `nawy` = this parcel ships from the FBN warehouse.
                'fulfilled_by' => $subOrder->fulfilled_by ?? 'seller',
                'total' => MoneyResource::make((string) $subOrder->total),
                'shipping' => MoneyResource::make((string) $subOrder->shipping_total),
                'method' => $subOrder->shipping_method,
                'delivery' => [
                    'from' => $subOrder->estimated_delivery_from?->toDateString(),
                    'by' => $subOrder->estimated_delivery_by?->toDateString(),
                ],
                // ADR 15: the customer's copy of the code of the open delivery attempt.
                'delivery_code' => $this->openDeliveryCode($subOrder),
                'delivered_at' => $subOrder->delivered_at?->toIso8601String(),
                'return_until' => $this->returnUntil($subOrder, $returnDays)?->toIso8601String(),
                'actions' => $this->actions($subOrder, $invoiceReady, $returnDays),
                'items' => $subOrder->items->map(fn ($item): array => [
                    'id' => $item->id,
                    'listing_id' => $item->listing_id,
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
            // QA-043: the address as it was at checkout, without the internal address-book id.
            'shipping_address' => is_array($this->shipping_address) ? Arr::except($this->shipping_address, ['address_id']) : $this->shipping_address,
            'invoice_available' => $invoiceReady,
        ];
    }

    /**
     * QC-026: what the customer can do with a shipment (Track / Invoice /
     * Cancel / Return / Review / Buy again / Dispute).
     *
     * @return array<string, bool>
     */
    private function actions(SubOrder $subOrder, bool $invoiceReady, int $returnDays): array
    {
        $status = $subOrder->status;
        $received = in_array($status, [SubOrderStatus::Delivered, SubOrderStatus::Completed], true);
        $returnUntil = $this->returnUntil($subOrder, $returnDays);

        return [
            'track' => in_array($status, [SubOrderStatus::Packed, SubOrderStatus::Shipped, SubOrderStatus::OutForDelivery], true),
            'invoice' => $invoiceReady,
            'cancel' => $status->isBeforeShipping() && in_array(SubOrderStatus::Cancelled, $status->allowedNext(), true),
            'return' => $status === SubOrderStatus::Delivered && $returnUntil !== null && $returnUntil->isFuture(),
            'review' => $received,
            'buy_again' => $received || in_array($status, [SubOrderStatus::Cancelled, SubOrderStatus::Returned, SubOrderStatus::Refunded], true),
            'dispute' => in_array($status, [SubOrderStatus::Shipped, SubOrderStatus::OutForDelivery, SubOrderStatus::Delivered, SubOrderStatus::Completed], true),
        ];
    }

    /**
     * The last day to start a return: delivery + the longest window of its lines.
     */
    private function returnUntil(SubOrder $subOrder, int $countryDays): ?CarbonImmutable
    {
        if ($subOrder->delivered_at === null) {
            return null;
        }

        $days = (int) ($subOrder->items->max(fn ($item): int => (int) ($item->return_window_days ?? $countryDays)) ?? $countryDays);

        return $subOrder->delivered_at->addDays($days);
    }

    /**
     * QC-050: read from the eager-loaded attempts when the list loaded them.
     */
    private function openDeliveryCode(SubOrder $subOrder): ?string
    {
        if ($subOrder->relationLoaded('deliveryAssignments')) {
            return $subOrder->deliveryAssignments->filter(fn (DeliveryAssignment $assignment): bool => in_array($assignment->status, AssignmentStatus::open(), true))
                ->sortByDesc('id')->first()?->delivery_code;
        }

        return DeliveryAssignment::query()->where('sub_order_id', $subOrder->id)->whereIn('status', AssignmentStatus::open())->latest('id')->value('delivery_code');
    }

    /**
     * A tax invoice exists once the order is paid (online), or a COD
     * shipment was delivered.
     */
    private function invoiceReady(): bool
    {
        return InvoicePdf::isReady($this->resource);
    }

    /**
     * Product slug and main image per listing of the order's lines (one query).
     *
     * @return array<int, array{slug: string, image: array<string, mixed>|null}>
     */
    private function lineImages(): array
    {
        $items = $this->subOrders->flatMap(fn ($subOrder) => $subOrder->items);
        $listingIds = $items->pluck('listing_id')->filter()->unique()->values();

        if ($listingIds->isEmpty()) {
            return [];
        }

        // QC-050: the orders list eager-loads items.listing.variant.product.media.
        if ($items->every(fn ($item): bool => $item->relationLoaded('listing'))) {
            return $items->filter(fn ($item): bool => $item->listing?->variant?->product !== null)
                ->mapWithKeys(fn ($item): array => [$item->listing_id => [
                    'slug' => $item->listing->variant->product->slug,
                    'image' => $item->listing->variant->product->primaryImage($item->listing->product_variant_id)?->toPublicArray(),
                ]])->all();
        }

        return Listing::query()->whereIn('id', $listingIds)->with('variant.product.media')->get()
            ->mapWithKeys(fn (Listing $listing): array => [$listing->id => [
                'slug' => $listing->variant->product->slug,
                'image' => $listing->variant->product->primaryImage($listing->product_variant_id)?->toPublicArray(),
            ]])->all();
    }
}
