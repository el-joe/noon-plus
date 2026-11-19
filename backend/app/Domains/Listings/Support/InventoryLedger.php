<?php

namespace App\Domains\Listings\Support;

use App\Domains\Fulfilment\Support\WarehouseStockService;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Exceptions\InsufficientStock;
use App\Domains\Listings\Models\Listing;
use Illuminate\Support\Facades\DB;

/**
 * Records stock movements and keeps the listing status in step with stock:
 * selling the last unit moves an active listing to out_of_stock, restocking
 * an out_of_stock listing makes it active again. For an FBN listing the
 * move is mirrored on the warehouse stock ({@see WarehouseStockService}).
 */
class InventoryLedger
{
    public function __construct(private ListingStateMachine $stateMachine) {}

    public function available(Listing $listing): int
    {
        return (int) $listing->movements()->sum('quantity');
    }

    /**
     * @param  int  $quantity  positive to add stock, negative to remove it
     */
    public function move(
        Listing $listing,
        int $quantity,
        string $reason,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $actorUserId = null,
    ): int {
        return DB::connection($listing->getConnectionName())->transaction(function () use ($listing, $quantity, $reason, $referenceType, $referenceId, $actorUserId): int {
            $locked = Listing::query()->whereKey($listing->getKey())->lockForUpdate()->firstOrFail();
            $available = $this->available($locked);

            if ($available + $quantity < 0) {
                throw new InsufficientStock($available, -$quantity);
            }

            $locked->movements()->create([
                'quantity' => $quantity,
                'reason' => $reason,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'actor_user_id' => $actorUserId,
            ]);

            $balance = $available + $quantity;

            if ($locked->fulfilment === 'fbn') {
                app(WarehouseStockService::class)->mirror($locked, $quantity, $reason, $referenceType, $referenceId, $actorUserId);
            }

            if ($balance === 0 && $locked->status === ListingStatus::Active) {
                $this->stateMachine->transition($locked, ListingStatus::OutOfStock, $actorUserId, 'Sold out');
            } elseif ($balance > 0 && $locked->status === ListingStatus::OutOfStock) {
                $this->stateMachine->transition($locked, ListingStatus::Active, $actorUserId, 'Restocked');
            }

            $listing->setRawAttributes($locked->getAttributes(), true);

            return $balance;
        });
    }
}
