<?php

namespace App\Domains\Migration\Steps;

use Illuminate\Support\Str;

/**
 * Carts (plan §9 "in-flight objects": migrate items, drop locks). A cart
 * keeps its customer (or guest token); items point to the migrated listing.
 * Prices are not copied: the cart shows the listing's current price.
 */
class CartsStep extends Step
{
    public function name(): string
    {
        return 'carts';
    }

    protected function order(): array
    {
        return ['carts', 'cart_items'];
    }

    protected function migrateCarts(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];
            $code = $this->singleCountry($table, $row);

            if ($code === null) {
                continue;
            }

            $userId = $row['user_id'] ? $this->ids->find('customers', (string) $row['user_id'], 'central', 'users') : null;

            if ($row['user_id'] && $userId === null) {
                $this->quarantine->put($table, $id, 'owner_unresolved', 'cart customer not migrated', $code, $row);

                continue;
            }

            if ($userId === null && ! $row['session_token']) {
                $this->quarantine->put($table, $id, 'owner_unresolved', 'cart has neither customer nor session', $code, $row);

                continue;
            }

            $this->writer->upsert($table, $id, $code, 'carts', [
                'customer_user_id' => $userId,
                'guest_token' => $userId === null ? Str::limit((string) $row['session_token'], 64, '') : null,
                ...$this->timestamps($row),
            ], $userId === null ? null : ['customer_user_id' => $userId]);
        }
    }

    protected function migrateCartItems(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];
            $carts = $this->ids->targets('carts', (string) $row['cart_id'], 'carts');

            if ($carts === []) {
                $this->quarantine->put($table, $id, 'parent_unmigrated', 'cart not migrated', null, $row);

                continue;
            }

            $code = array_key_first($carts);
            $listingId = $row['vendor_listing_id'] ? $this->ids->find('vendor_listings', (string) $row['vendor_listing_id'], $code, 'listings') : null;

            if ($listingId === null) {
                $this->quarantine->put($table, $id, 'parent_unmigrated', 'cart item listing not migrated (or an admin / marketer listing)', $code, $row);

                continue;
            }

            $this->writer->upsert($table, $id, $code, 'cart_items', [
                'cart_id' => $carts[$code],
                'listing_id' => $listingId,
                'quantity' => max(1, (int) $row['quantity']),
                'warranty_plan_id' => $row['warranty_plan_id'] ? $this->ids->find('warranty_plans', (string) $row['warranty_plan_id'], $code, 'warranty_plans') : null,
                ...$this->timestamps($row),
            ], ['cart_id' => $carts[$code], 'listing_id' => $listingId]);
        }
    }
}
