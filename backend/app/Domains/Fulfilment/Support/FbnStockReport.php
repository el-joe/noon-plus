<?php

namespace App\Domains\Fulfilment\Support;

use App\Domains\Fulfilment\Models\WarehouseStock;
use App\Domains\Listings\Models\Listing;
use Illuminate\Support\Collection;

/**
 * A seller's FBN stock per listing (SKU) and warehouse, with totals and
 * the low-stock / long-term / unsellable alerts (Seller Center and the
 * vendor app read the same rows).
 */
class FbnStockReport
{
    public function __construct(private FbnSettings $settings) {}

    /**
     * @return Collection<int, array{listing: Listing, sku: string, title: string, on_hand: int, reserved: int, available: int, damaged: int, low_stock: bool, long_term: bool, unsellable: bool, warehouses: array<int, array{warehouse_id: int, code: string, name: string, bin: string|null, on_hand: int, reserved: int, available: int, damaged: int}>}>
     */
    public function forVendor(int $vendorId): Collection
    {
        $threshold = $this->settings->int('low_stock_threshold');
        $listings = Listing::query()->where('vendor_id', $vendorId)->where('fulfilment', 'fbn')->with('variant.product')->orderBy('id')->get();
        $stock = WarehouseStock::query()->whereIn('listing_id', $listings->pluck('id'))->with('warehouse')->get()->groupBy('listing_id');

        return $listings->map(function (Listing $listing) use ($stock, $threshold): array {
            $rows = $stock->get($listing->id, collect());
            $available = (int) $rows->sum(fn (WarehouseStock $row): int => $row->available());

            return [
                'listing' => $listing,
                'sku' => $listing->variant->sku,
                'title' => $listing->variant->product->title(),
                'on_hand' => (int) $rows->sum('on_hand'),
                'reserved' => (int) $rows->sum('reserved'),
                'available' => $available,
                'damaged' => (int) $rows->sum('damaged'),
                'low_stock' => $available <= $threshold,
                'long_term' => $rows->contains(fn (WarehouseStock $row): bool => $row->long_term_flagged_at !== null),
                'unsellable' => $rows->contains(fn (WarehouseStock $row): bool => $row->unsellable_flagged_at !== null),
                'warehouses' => $rows->map(fn (WarehouseStock $row): array => [
                    'warehouse_id' => $row->warehouse_id,
                    'code' => $row->warehouse->code,
                    'name' => $row->warehouse->name(),
                    'bin' => $row->bin_code,
                    'on_hand' => $row->on_hand,
                    'reserved' => $row->reserved,
                    'available' => $row->available(),
                    'damaged' => $row->damaged,
                ])->values()->all(),
            ];
        });
    }
}
