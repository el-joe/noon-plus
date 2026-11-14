<?php

namespace App\Domains\Cart\Support;

use App\Domains\Cart\Models\CartInventoryLock;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\InventoryLedger;

/**
 * Stock a cart may still take: ledger stock minus other carts' live holds.
 */
class StockAvailability
{
    public function __construct(private InventoryLedger $inventoryLedger) {}

    public function availableFor(Listing $listing, ?int $cartId = null): int
    {
        $heldElsewhere = (int) CartInventoryLock::query()
            ->where('listing_id', $listing->id)
            ->where('expires_at', '>', now())
            ->when($cartId !== null, fn ($query) => $query->where('cart_id', '!=', $cartId))
            ->sum('quantity');

        return max(0, $this->inventoryLedger->available($listing) - $heldElsewhere);
    }
}
