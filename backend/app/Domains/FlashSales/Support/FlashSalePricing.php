<?php

namespace App\Domains\FlashSales\Support;

use App\Domains\Cart\Exceptions\CartRejected;
use App\Domains\FlashSales\Enums\FlashSaleEventStatus;
use App\Domains\FlashSales\Enums\FlashSaleSubmissionStatus;
use App\Domains\FlashSales\Models\FlashSaleSubmission;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\OrderItem;
use Illuminate\Support\Collection;

/**
 * Flash prices in the cart (SRS §10). A deal applies from the event start
 * (inclusive) to its end (exclusive) while the event and submission are
 * approved or live and units remain under the cap, so prices start and
 * revert exactly on time whatever the 5-minute job does. Each customer may
 * buy at most the event's per-customer maximum across all their orders.
 */
class FlashSalePricing
{
    /**
     * Deals running now for these listings, the lowest flash price first.
     *
     * @param  array<int, int>  $listingIds
     * @return Collection<int, FlashSaleSubmission> listing id => submission (with event)
     */
    public function activeDeals(array $listingIds, bool $lockForUpdate = false): Collection
    {
        if ($listingIds === []) {
            return collect();
        }

        return FlashSaleSubmission::query()
            ->select('flash_sale_submissions.*')
            ->join('flash_sale_events', 'flash_sale_events.id', '=', 'flash_sale_submissions.flash_sale_event_id')
            ->whereIn('flash_sale_submissions.listing_id', $listingIds)
            ->whereIn('flash_sale_submissions.status', FlashSaleSubmissionStatus::pricing())
            ->whereIn('flash_sale_events.status', FlashSaleEventStatus::pricing())
            ->where('flash_sale_events.starts_at', '<=', now())
            ->where('flash_sale_events.ends_at', '>', now())
            ->whereColumn('flash_sale_submissions.sold_quantity', '<', 'flash_sale_submissions.quantity_cap')
            ->orderBy('flash_sale_submissions.flash_price')
            ->orderBy('flash_sale_submissions.id')
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())
            ->with('event')
            ->get()
            ->unique('listing_id')
            ->keyBy('listing_id');
    }

    /**
     * Units of each deal the customer already bought (cancelled shipments excluded).
     *
     * @param  array<int, int>  $submissionIds
     * @return array<int, int> submission id => quantity
     */
    public function purchasedBy(?int $customerUserId, array $submissionIds): array
    {
        if ($customerUserId === null || $submissionIds === []) {
            return [];
        }

        return OrderItem::query()
            ->join('sub_orders', 'sub_orders.id', '=', 'order_items.sub_order_id')
            ->join('orders', 'orders.id', '=', 'sub_orders.order_id')
            ->where('orders.customer_user_id', $customerUserId)
            ->whereIn('order_items.flash_sale_submission_id', $submissionIds)
            ->where('sub_orders.status', '!=', SubOrderStatus::Cancelled->value)
            ->groupBy('order_items.flash_sale_submission_id')
            ->selectRaw('order_items.flash_sale_submission_id as submission_id, sum(order_items.quantity) as quantity')
            ->pluck('quantity', 'submission_id')
            ->map(fn ($quantity): int => (int) $quantity)
            ->all();
    }

    /**
     * How many units this customer may still take at the flash price.
     */
    public function allowance(FlashSaleSubmission $deal, ?int $customerUserId): int
    {
        $bought = $this->purchasedBy($customerUserId, [$deal->id])[$deal->id] ?? 0;

        return max(0, min($deal->remainingQuantity(), $deal->event->max_qty_per_customer - $bought));
    }

    /**
     * Refuse a cart quantity above the deal's per-customer maximum or above
     * the units left at the flash price.
     */
    public function assertWithinLimits(FlashSaleSubmission $deal, int $quantity, ?int $customerUserId): void
    {
        $bought = $this->purchasedBy($customerUserId, [$deal->id])[$deal->id] ?? 0;
        $perCustomerLeft = max(0, $deal->event->max_qty_per_customer - $bought);

        if ($quantity > $perCustomerLeft) {
            throw new CartRejected('flash_sale_customer_limit', trans_choice(
                'This deal is limited to :max per customer; you can have up to :left in your cart.|This deal is limited to :max per customer; you can have up to :left in your cart.',
                $perCustomerLeft,
                ['max' => $deal->event->max_qty_per_customer, 'left' => $perCustomerLeft],
            ));
        }

        if ($quantity > $deal->remainingQuantity()) {
            throw new CartRejected('flash_sale_sold_out', trans_choice(
                'Only :count left at the deal price.|Only :count left at the deal price.',
                $deal->remainingQuantity(),
                ['count' => $deal->remainingQuantity()],
            ));
        }
    }
}
