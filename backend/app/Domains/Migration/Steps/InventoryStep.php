<?php

namespace App\Domains\Migration\Steps;

/**
 * Stock (MG3, rule R2 via listing). Legacy inventory movements are copied as
 * they were (append-only history); then each warehouse inventory row adds one
 * `legacy_opening` movement for the gap between the legacy available quantity
 * and its movement history, so the new stock (Σ movements) equals the old
 * `quantity_available` exactly. Admin / marketer listings have no new
 * equivalent and are quarantined.
 */
class InventoryStep extends Step
{
    public function name(): string
    {
        return 'inventory';
    }

    protected function order(): array
    {
        return ['inventory_movements', 'warehouse_inventories'];
    }

    protected function migrateInventoryMovements(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $inventory = $this->source->find('warehouse_inventories', (string) $row['warehouse_inventory_id']);
            $target = $inventory === null ? null : $this->listingOf('warehouse_inventories', $inventory);

            if ($target === null) {
                $this->quarantine->put($table, (string) $row['id'], 'parent_unmigrated', 'stock row of a listing that was not migrated', null, $row);

                continue;
            }

            [$code, $listingId] = $target;

            if (! $this->context->allows($code)) {
                continue;
            }

            $this->writer->upsert($table, (string) $row['id'], $code, 'inventory_movements', [
                'listing_id' => $listingId,
                'quantity' => (int) $row['quantity_delta'],
                'reason' => mb_substr('legacy_'.$row['movement_type'], 0, 32),
                'reference_type' => $row['reference_type'] === null ? null : 'legacy_'.$row['reference_type'],
                'reference_id' => null,
                'actor_user_id' => null,
                'created_at' => $row['created_at'] ?? now(),
            ]);
        }
    }

    protected function migrateWarehouseInventories(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $target = $this->listingOf($table, $row);

            if ($target === null) {
                continue;
            }

            [$code, $listingId] = $target;

            if (! $this->context->allows($code)) {
                continue;
            }

            $history = (int) $this->source->connection()->table('inventory_movements')->where('warehouse_inventory_id', $row['id'])->sum('quantity_delta');
            $opening = (int) $row['quantity_available'] - $history;

            if ($opening === 0) {
                $this->context->count($table, 'no_opening_needed');
                $this->writer->link($table, (string) $row['id'], $code, 'listings', $listingId, 'folded');

                continue;
            }

            $this->writer->upsert($table, (string) $row['id'], $code, 'inventory_movements', [
                'listing_id' => $listingId,
                'quantity' => $opening,
                'reason' => 'legacy_opening',
                'reference_type' => 'legacy_inventory',
                'reference_id' => null,
                'actor_user_id' => null,
                'created_at' => $row['first_stocked_at'] ?? $row['created_at'] ?? now(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $inventory
     * @return array{0: string, 1: int}|null
     */
    private function listingOf(string $table, array $inventory): ?array
    {
        if ($inventory['vendor_listing_id'] === null) {
            $this->quarantine->put($table, (string) $inventory['id'], 'shape_unsupported', 'stock of an admin or marketer listing (no new equivalent)', null, $inventory);

            return null;
        }

        $targets = $this->ids->targets('vendor_listings', (string) $inventory['vendor_listing_id'], 'listings');

        if ($targets === []) {
            $this->quarantine->put($table, (string) $inventory['id'], 'parent_unmigrated', 'listing not migrated', null, $inventory);

            return null;
        }

        return [array_key_first($targets), reset($targets)];
    }
}
