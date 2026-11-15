<?php

namespace App\Domains\Catalog\Http\Resources;

use App\Domains\FlashSales\Models\FlashSaleSubmission;
use App\Domains\Listings\Models\Listing;
use App\Domains\Shipping\Support\DeliveryEstimate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A seller's offer on the product page, with the running flash deal (its
 * price replaces the regular one) and the "Get it by" delivery promise.
 *
 * @mixin Listing
 */
class OfferResource extends JsonResource
{
    public function __construct(
        Listing $resource,
        private ?FlashSaleSubmission $flashDeal = null,
        private ?DeliveryEstimate $delivery = null,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $regularPrice = $this->effectivePrice();

        return [
            'listing_id' => $this->id,
            'sku' => $this->variant->sku,
            'options' => $this->variant->option_values,
            'seller' => ['slug' => $this->vendor->slug, 'name' => $this->vendor->store_name, 'rating' => $this->vendor->rating, 'rating_count' => (int) $this->vendor->rating_count],
            'price' => MoneyResource::make($this->flashDeal !== null ? (string) $this->flashDeal->flash_price : $regularPrice),
            'was_price' => match (true) {
                $this->flashDeal !== null => MoneyResource::make($regularPrice),
                $this->sale_price !== null => MoneyResource::make((string) $this->price),
                default => null,
            },
            'flash_sale' => $this->flashDeal === null ? null : [
                'event' => $this->flashDeal->event->display_name ?? $this->flashDeal->event->name,
                'ends_at' => $this->flashDeal->event->ends_at->toIso8601String(),
                'remaining' => $this->flashDeal->remainingQuantity(),
                'max_per_customer' => $this->flashDeal->event->max_qty_per_customer,
            ],
            'delivery' => $this->delivery?->toArray(),
            'fulfilment' => $this->fulfilment,
            'condition' => $this->condition,
            'in_stock' => (int) $this->available_quantity,
        ];
    }
}
