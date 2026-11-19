<?php

namespace App\Domains\Fulfilment\Support;

use App\Domains\Fulfilment\Enums\ReservationStatus;
use App\Domains\Fulfilment\Enums\StockBucket;
use App\Domains\Fulfilment\Models\FbnReservation;
use App\Domains\Fulfilment\Models\Warehouse;
use App\Domains\Fulfilment\Models\WarehouseStock;
use App\Domains\Fulfilment\Models\WarehouseStockMovement;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Orders\Models\SubOrder;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Warehouse stock of FBN listings: on hand, reserved, damaged per warehouse
 * and listing; available = on hand − reserved.
 *
 * The listing's own ledger ({@see InventoryLedger}) stays the sellable
 * quantity everyone reads (cart, checkout, storefront). For an FBN listing
 * it always equals the sum of the warehouses' available units:
 *
 * - warehouse events that change what can be sold (receiving, damage,
 *   transfers, removals, adjustments) move both, the ledger with an
 *   `fbn_…` reason;
 * - ledger moves made elsewhere are mirrored here ({@see self::mirror()}):
 *   a sale reserves warehouse units, a cancellation releases them (or puts
 *   a dispatched parcel back on the shelf), a return / sample return
 *   restocks, any other removal takes free units.
 *
 * Dispatch (sub-order shipped) turns reserved units into units gone (on
 * hand and reserved both down), which leaves the sellable quantity as is.
 * No counter and no available quantity ever goes below zero. The listing
 * row is locked before the stock row, in the same order checkout uses.
 */
class WarehouseStockService
{
    public function __construct(private InventoryLedger $ledger) {}

    public function stockFor(Warehouse|int $warehouse, Listing $listing): WarehouseStock
    {
        $warehouseId = $warehouse instanceof Warehouse ? $warehouse->id : $warehouse;

        return WarehouseStock::query()->firstOrNew(['warehouse_id' => $warehouseId, 'listing_id' => $listing->id]);
    }

    /**
     * Total available (sellable) units of a listing across warehouses.
     */
    public function available(Listing $listing): int
    {
        return (int) WarehouseStock::query()->where('listing_id', $listing->id)->sum(DB::raw('on_hand - reserved'));
    }

    public function receive(Warehouse $warehouse, Listing $listing, int $good, int $damaged, ?string $binCode, string $referenceType, int $referenceId, ?int $actorUserId): WarehouseStock
    {
        return $this->change($warehouse->id, $listing, [StockBucket::OnHand->value => $good, StockBucket::Damaged->value => $damaged], 'inbound_received', $referenceType, $referenceId, $actorUserId, ledgerDelta: $good, binCode: $binCode);
    }

    /**
     * Admin stock adjustment of one counter (on hand changes what can be
     * sold; damaged does not).
     */
    public function adjust(Warehouse $warehouse, Listing $listing, StockBucket $bucket, int $delta, string $reason, string $note, ?int $actorUserId): WarehouseStock
    {
        if ($bucket === StockBucket::Reserved) {
            throw new TransitionRefused(__('Reserved units follow their orders and cannot be adjusted.'));
        }

        return $this->change($warehouse->id, $listing, [$bucket->value => $delta], 'adjustment_'.$reason, 'adjustment', null, $actorUserId, ledgerDelta: $bucket === StockBucket::OnHand ? $delta : 0, note: $note);
    }

    /**
     * Found damaged on the shelf: sellable → damaged.
     */
    public function markDamaged(Warehouse $warehouse, Listing $listing, int $quantity, string $note, ?int $actorUserId): WarehouseStock
    {
        return $this->change($warehouse->id, $listing, [StockBucket::OnHand->value => -$quantity, StockBucket::Damaged->value => $quantity], 'marked_damaged', 'adjustment', null, $actorUserId, ledgerDelta: -$quantity, note: $note);
    }

    public function transferOut(int $warehouseId, Listing $listing, int $quantity, int $transferId, ?int $actorUserId): WarehouseStock
    {
        return $this->change($warehouseId, $listing, [StockBucket::OnHand->value => -$quantity], 'transfer_out', 'fbn_transfer', $transferId, $actorUserId, ledgerDelta: -$quantity);
    }

    public function transferIn(int $warehouseId, Listing $listing, int $quantity, int $transferId, ?int $actorUserId): WarehouseStock
    {
        return $this->change($warehouseId, $listing, [StockBucket::OnHand->value => $quantity], 'transfer_in', 'fbn_transfer', $transferId, $actorUserId, ledgerDelta: $quantity);
    }

    /**
     * Removal requested: sellable units are held (off sale) until picked;
     * damaged units must be there.
     */
    public function holdForRemoval(int $warehouseId, Listing $listing, string $source, int $quantity, int $removalId, ?int $actorUserId): void
    {
        if ($source === 'damaged') {
            $stock = $this->stockFor($warehouseId, $listing);

            if ($stock->damaged < $quantity) {
                throw new TransitionRefused(__('Only :count damaged units of :sku are in this warehouse.', ['count' => $stock->damaged, 'sku' => $listing->variant->sku]));
            }

            return;
        }

        $this->change($warehouseId, $listing, [StockBucket::Reserved->value => $quantity], 'removal_hold', 'fbn_removal_order', $removalId, $actorUserId, ledgerDelta: -$quantity);
    }

    public function releaseRemovalHold(int $warehouseId, Listing $listing, string $source, int $quantity, int $removalId, ?int $actorUserId): void
    {
        if ($source !== 'damaged') {
            $this->change($warehouseId, $listing, [StockBucket::Reserved->value => -$quantity], 'removal_released', 'fbn_removal_order', $removalId, $actorUserId, ledgerDelta: $quantity);
        }
    }

    public function pickForRemoval(int $warehouseId, Listing $listing, string $source, int $quantity, int $removalId, ?int $actorUserId): void
    {
        $deltas = $source === 'damaged'
            ? [StockBucket::Damaged->value => -$quantity]
            : [StockBucket::OnHand->value => -$quantity, StockBucket::Reserved->value => -$quantity];

        $this->change($warehouseId, $listing, $deltas, 'removal_picked', 'fbn_removal_order', $removalId, $actorUserId);
    }

    /**
     * Checkout took `$quantity` units of the listing for an order item:
     * reserve them in the warehouses with the most free units.
     */
    public function reserveForSale(Listing $listing, int $quantity, OrderItem $orderItem): int
    {
        $reserved = 0;

        foreach ($this->rowsByAvailable($listing) as $row) {
            $take = min($row->available(), $quantity - $reserved);

            if ($take <= 0) {
                continue;
            }

            $this->change($row->warehouse_id, $listing, [StockBucket::Reserved->value => $take], 'order_reserved', 'order_item', $orderItem->id, null);
            FbnReservation::query()->create([
                'order_item_id' => $orderItem->id, 'sub_order_id' => $orderItem->sub_order_id, 'listing_id' => $listing->id,
                'warehouse_id' => $row->warehouse_id, 'quantity' => $take, 'status' => ReservationStatus::Reserved,
            ]);
            $reserved += $take;

            if ($reserved === $quantity) {
                break;
            }
        }

        return $reserved;
    }

    /**
     * The order item was cancelled and `$quantity` units went back on sale:
     * release what is still reserved, put dispatched units back on the
     * shelf, and restock anything not covered by a reservation.
     */
    public function releaseForOrderItem(Listing $listing, int $orderItemId, int $quantity, ?int $actorUserId): void
    {
        $covered = 0;

        foreach (FbnReservation::query()->where('order_item_id', $orderItemId)->whereIn('status', [ReservationStatus::Reserved, ReservationStatus::Dispatched])->orderBy('id')->get() as $reservation) {
            if ($reservation->status === ReservationStatus::Reserved) {
                $this->change($reservation->warehouse_id, $listing, [StockBucket::Reserved->value => -$reservation->quantity], 'order_released', 'order_item', $orderItemId, $actorUserId);
                $reservation->forceFill(['status' => ReservationStatus::Released])->save();
            } else {
                $this->change($reservation->warehouse_id, $listing, [StockBucket::OnHand->value => $reservation->quantity], 'order_returned_to_stock', 'order_item', $orderItemId, $actorUserId);
                $reservation->forceFill(['status' => ReservationStatus::Returned])->save();
            }

            $covered += $reservation->quantity;
        }

        if ($covered < $quantity) {
            $this->restock($listing, $quantity - $covered, 'order_item', $orderItemId, $actorUserId, 'order_returned_to_stock');
        }
    }

    /**
     * The warehouse handed the sub-order's parcel over: its reserved units
     * leave the building.
     */
    public function dispatchSubOrder(SubOrder $subOrder, ?int $actorUserId): int
    {
        $units = 0;

        foreach (FbnReservation::query()->where('sub_order_id', $subOrder->id)->where('status', ReservationStatus::Reserved)->orderBy('id')->get() as $reservation) {
            $listing = Listing::query()->findOrFail($reservation->listing_id);
            $this->change($reservation->warehouse_id, $listing, [StockBucket::OnHand->value => -$reservation->quantity, StockBucket::Reserved->value => -$reservation->quantity], 'order_dispatched', 'order_item', $reservation->order_item_id, $actorUserId);
            $reservation->forceFill(['status' => ReservationStatus::Dispatched])->save();
            $units += $reservation->quantity;
        }

        return $units;
    }

    /**
     * Mirror a listing ledger move made outside this service (see class
     * doc). Moves this service makes itself carry an `fbn_` reason.
     */
    public function mirror(Listing $listing, int $quantity, string $reason, ?string $referenceType, ?int $referenceId, ?int $actorUserId): void
    {
        if ($quantity === 0 || str_starts_with($reason, 'fbn_') || $listing->fulfilment !== 'fbn') {
            return;
        }

        match (true) {
            $reason === 'sale' && $referenceType === 'order_item' => $this->reserveForSale($listing, -$quantity, OrderItem::query()->findOrFail($referenceId)),
            $reason === 'order_cancelled' && $referenceType === 'order_item' => $this->releaseForOrderItem($listing, (int) $referenceId, $quantity, $actorUserId),
            $quantity > 0 => $this->restock($listing, $quantity, $referenceType, $referenceId, $actorUserId, $reason),
            default => $this->takeFree($listing, -$quantity, $reason, $referenceType, $referenceId, $actorUserId),
        };
    }

    /**
     * Units back on the shelf (customer return, returned sample): to the
     * warehouse that last shipped the listing, else the one holding most of
     * it, else the first active warehouse. No warehouse → nothing to do.
     */
    private function restock(Listing $listing, int $quantity, ?string $referenceType, ?int $referenceId, ?int $actorUserId, string $reason): void
    {
        $warehouseId = FbnReservation::query()->where('listing_id', $listing->id)->where('status', ReservationStatus::Dispatched)->latest('id')->value('warehouse_id')
            ?? WarehouseStock::query()->where('listing_id', $listing->id)->orderByDesc('on_hand')->value('warehouse_id')
            ?? Warehouse::query()->active()->orderBy('id')->value('id');

        if ($warehouseId !== null) {
            $this->change((int) $warehouseId, $listing, [StockBucket::OnHand->value => $quantity], $reason, $referenceType, $referenceId, $actorUserId);
        }
    }

    /**
     * Units taken without an order (e.g. a promotion sample): from the
     * warehouses with the most free units.
     */
    private function takeFree(Listing $listing, int $quantity, string $reason, ?string $referenceType, ?int $referenceId, ?int $actorUserId): void
    {
        $taken = 0;

        foreach ($this->rowsByAvailable($listing) as $row) {
            $take = min($row->available(), $quantity - $taken);

            if ($take > 0) {
                $this->change($row->warehouse_id, $listing, [StockBucket::OnHand->value => -$take], $reason, $referenceType, $referenceId, $actorUserId);
                $taken += $take;
            }

            if ($taken === $quantity) {
                break;
            }
        }
    }

    /**
     * @return Collection<int, WarehouseStock>
     */
    private function rowsByAvailable(Listing $listing): Collection
    {
        return WarehouseStock::query()->where('listing_id', $listing->id)
            ->whereIn('warehouse_id', Warehouse::query()->active()->select('id'))
            ->get()->sortByDesc(fn (WarehouseStock $row): int => $row->available())->values();
    }

    /**
     * Apply counter deltas to one warehouse × listing row under lock, write
     * a movement per counter and, when sellable units change, the listing
     * ledger with an `fbn_` reason.
     *
     * @param  array<string, int>  $deltas  bucket => delta
     */
    private function change(int $warehouseId, Listing $listing, array $deltas, string $reason, ?string $referenceType, ?int $referenceId, ?int $actorUserId, int $ledgerDelta = 0, ?string $note = null, ?string $binCode = null): WarehouseStock
    {
        return DB::connection($listing->getConnectionName())->transaction(function () use ($warehouseId, $listing, $deltas, $reason, $referenceType, $referenceId, $actorUserId, $ledgerDelta, $note, $binCode): WarehouseStock {
            Listing::query()->whereKey($listing->id)->lockForUpdate()->first();
            WarehouseStock::query()->firstOrCreate(['warehouse_id' => $warehouseId, 'listing_id' => $listing->id]);
            $row = WarehouseStock::query()->where('warehouse_id', $warehouseId)->where('listing_id', $listing->id)->lockForUpdate()->firstOrFail();
            $before = ['on_hand' => $row->on_hand, 'damaged' => $row->damaged];

            foreach ($deltas as $bucket => $delta) {
                $row->{$bucket} = $row->{$bucket} + $delta;
            }

            if ($row->on_hand < 0 || $row->reserved < 0 || $row->damaged < 0 || $row->reserved > $row->on_hand) {
                throw new TransitionRefused(__('Not enough stock of :sku in this warehouse (on hand :on_hand, reserved :reserved, damaged :damaged).', [
                    'sku' => $listing->variant?->sku ?? $listing->id, 'on_hand' => $before['on_hand'], 'reserved' => $row->getOriginal('reserved'), 'damaged' => $before['damaged'],
                ]));
            }

            if ($row->on_hand > 0 && $before['on_hand'] === 0) {
                $row->first_received_at ??= now();
            } elseif ($row->on_hand === 0) {
                $row->first_received_at = null;
                $row->long_term_flagged_at = null;
            }

            if ($row->damaged > 0 && $before['damaged'] === 0) {
                $row->damaged_since = now();
            } elseif ($row->damaged === 0) {
                $row->damaged_since = null;
                $row->unsellable_flagged_at = null;
            }

            if ($binCode !== null && $binCode !== '') {
                $row->bin_code = $binCode;
            }

            $row->save();

            foreach ($deltas as $bucket => $delta) {
                if ($delta !== 0) {
                    WarehouseStockMovement::query()->create([
                        'warehouse_id' => $warehouseId, 'listing_id' => $listing->id, 'bucket' => $bucket, 'quantity' => $delta,
                        'reason' => mb_substr($reason, 0, 40), 'reference_type' => $referenceType, 'reference_id' => $referenceId,
                        'actor_user_id' => $actorUserId, 'note' => $note === null ? null : mb_substr($note, 0, 255),
                    ]);
                }
            }

            if ($ledgerDelta !== 0) {
                $this->ledger->move($listing, $ledgerDelta, mb_substr('fbn_'.$reason, 0, 32), $referenceType, $referenceId, $actorUserId);
            }

            return $row;
        });
    }
}
