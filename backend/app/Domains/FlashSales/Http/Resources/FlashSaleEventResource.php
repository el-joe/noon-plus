<?php

namespace App\Domains\FlashSales\Http\Resources;

use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\FlashSales\Enums\FlashSaleSubmissionStatus;
use App\Domains\FlashSales\Models\FlashSaleEvent;
use App\Domains\FlashSales\Models\FlashSaleSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A flash sale on the Deals page: countdown times (UTC ISO 8601) and its
 * deals with flash and regular price and how much is left.
 *
 * @mixin FlashSaleEvent
 */
class FlashSaleEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isRunning = $this->starts_at->lessThanOrEqualTo(now()) && $this->ends_at->isFuture();

        return [
            'id' => $this->id,
            'name' => $this->display_name ?? $this->name,
            'state' => $isRunning ? 'live' : 'upcoming',
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'max_per_customer' => $this->max_qty_per_customer,
            'deals' => $this->submissions->map(fn (FlashSaleSubmission $deal): array => [
                'listing_id' => $deal->listing_id,
                'product' => [
                    'slug' => $deal->listing->variant->product->slug,
                    'title' => $deal->listing->variant->product->title(),
                ],
                'price' => MoneyResource::make((string) $deal->flash_price),
                'was_price' => MoneyResource::make((string) $deal->original_price),
                'sold_out' => $deal->status === FlashSaleSubmissionStatus::SoldOut || $deal->remainingQuantity() === 0,
                'claimed_percent' => (int) floor($deal->sold_quantity * 100 / max(1, $deal->quantity_cap)),
            ])->values(),
        ];
    }
}
