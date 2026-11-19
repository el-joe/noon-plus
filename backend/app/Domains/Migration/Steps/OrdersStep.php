<?php

namespace App\Domains\Migration\Steps;

use App\Domains\Migration\Support\MigrationContext;
use App\Domains\Payments\Enums\PaymentMethod;

/**
 * Orders (MG4, rule R1; plan §6). An order moves as one unit with its
 * sub-orders, items and status histories, inside one transaction: if any
 * part cannot be mapped (unknown country or currency, a vendor or listing
 * that was not migrated, a payment method with no new equivalent, totals
 * that do not add up) the whole order is quarantined and its children are
 * marked `parent_quarantined`. Amounts are copied, never recomputed; the
 * sub-order total is the sum of its legacy parts (subtotal + shipping + tax
 * + warranty) and must add up, with the order's COD fee and discounts, to
 * the legacy order total.
 */
class OrdersStep extends Step
{
    /**
     * Legacy gateway codes / payment methods → new payment method.
     *
     * @var array<string, string>
     */
    public const GATEWAYS = ['thawani' => 'thawani', 'stripe' => 'stripe', 'paypal' => 'paypal', 'paytabs' => 'paytabs', 'tabby' => 'tabby', 'tamara' => 'tamara'];

    public function name(): string
    {
        return 'orders';
    }

    protected function order(): array
    {
        return ['orders', 'sub_orders', 'order_items', 'order_status_histories'];
    }

    protected function migrateOrders(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $code = $this->singleCountry($table, $row);

            if ($code === null) {
                $this->quarantineChildren((string) $row['id']);

                continue;
            }

            $plan = $this->plan($row, $code);

            if (is_string($plan)) {
                $this->quarantine->put($table, (string) $row['id'], strtok($plan, ':') ?: 'unresolved', $plan, $code, $row);
                $this->quarantineChildren((string) $row['id']);

                continue;
            }

            $write = function () use ($row, $code, $plan, $table): void {
                $orderId = $this->writer->upsert($table, (string) $row['id'], $code, 'orders', $plan['order']);

                foreach ($plan['subs'] as $subId => $sub) {
                    $subOrderId = $this->writer->upsert('sub_orders', $subId, $code, 'sub_orders', ['order_id' => $orderId] + $sub['values']);

                    foreach ($sub['items'] as $itemId => $item) {
                        $this->writer->upsert('order_items', $itemId, $code, 'order_items', ['sub_order_id' => $subOrderId] + $item);
                    }
                }

                foreach ($plan['history'] as $historyId => $history) {
                    $subOrderId = $history['legacy_sub_order_id'] === null ? null : $this->ids->find('sub_orders', $history['legacy_sub_order_id'], $code, 'sub_orders');
                    unset($history['legacy_sub_order_id']);

                    $subOrderId === null
                        ? $this->writer->upsert('order_status_histories', $historyId, $code, 'state_transitions', ['subject_type' => 'order', 'subject_id' => $orderId] + array_diff_key($history, ['sub_order_id' => 1]))
                        : $this->writer->upsert('order_status_histories', $historyId, $code, 'order_status_history', ['sub_order_id' => $subOrderId] + array_diff_key($history, ['meta' => 1]));
                }
            };

            $this->context->dryRun ? $write() : MigrationContext::db($code)->transaction($write);
            $this->context->sum($table, $row['currency'], $row['total']);
        }
    }

    /**
     * Sub-orders, items and histories move with their order.
     */
    protected function migrateSubOrders(string $table): void
    {
        $this->countMoved($table, 'order_id');
    }

    protected function migrateOrderItems(string $table): void
    {
        $this->countMoved($table, 'order_id');
    }

    protected function migrateOrderStatusHistories(string $table): void
    {
        $this->countMoved($table, 'order_id');
    }

    /**
     * Everything the order needs, or the reason it cannot move.
     *
     * @param  array<string, mixed>  $row
     * @return array{order: array<string, mixed>, subs: array<string, array{values: array<string, mixed>, items: array<string, array<string, mixed>>}>, history: array<string, array<string, mixed>>}|string
     */
    private function plan(array $row, string $code): array|string
    {
        $currency = strtoupper((string) $row['currency']);

        if ($currency !== $this->countries->currencyOf($code)) {
            return "currency_mismatch: order in {$currency}, {$code} sells in ".$this->countries->currencyOf($code);
        }

        $customerUserId = $this->ids->find('customers', (string) $row['customer_id'], MigrationContext::CENTRAL, 'users');

        if ($customerUserId === null) {
            return 'owner_unresolved: customer not migrated';
        }

        $method = match ($row['payment_method']) {
            'cod' => PaymentMethod::Cod->value,
            'wallet' => PaymentMethod::Wallet->value,
            'card', 'bnpl' => self::GATEWAYS[strtolower((string) $row['payment_gateway_code'])] ?? null,
            default => null,
        };

        if ($method === null) {
            return "method_unmapped: payment {$row['payment_method']} / {$row['payment_gateway_code']} has no new equivalent";
        }

        if (bccomp(self::amount($row['wallet_amount_used'] ?? null), '0', 4) !== 0) {
            return 'shape_unsupported: part paid from the legacy wallet (no split-payment field)';
        }

        $connection = $this->source->connection();
        $subs = [];
        $subTotals = '0';

        foreach ($connection->table('sub_orders')->where('order_id', $row['id'])->orderBy('sub_order_number')->get() as $sub) {
            $sub = (array) $sub;

            if ($sub['seller_type'] !== 'vendor' || $sub['vendor_id'] === null) {
                return "shape_unsupported: {$sub['seller_type']} sub-order {$sub['sub_order_number']} (no vendor)";
            }

            $vendorId = $this->ids->find('vendors', (string) $sub['vendor_id'], $code, 'vendors');

            if ($vendorId === null) {
                return "parent_unmigrated: vendor of {$sub['sub_order_number']} not migrated to {$code}";
            }

            $total = bcadd(bcadd(bcadd(self::amount($sub['subtotal']), self::amount($sub['shipping']), 4), self::amount($sub['tax']), 4), self::amount($sub['warranty_revenue'] ?? null), 4);
            $subTotals = bcadd($subTotals, $total, 4);
            $shippingMethod = $sub['shipping_method_id'] ? $this->source->find('shipping_methods', (string) $sub['shipping_method_id']) : null;
            $items = [];

            foreach ($connection->table('order_items')->where('sub_order_id', $sub['id'])->orderBy('id')->get() as $item) {
                $item = (array) $item;
                $listingId = $this->listingFor($item, $code);

                if ($listingId === null) {
                    return "listing_unresolved: item {$item['sku']} has no migrated vendor listing";
                }

                $snapshot = (array) json_decode((string) $item['product_snapshot'], true);
                $items[(string) $item['id']] = [
                    'listing_id' => $listingId,
                    'sku' => mb_substr((string) $item['sku'], 0, 64),
                    'title' => mb_substr((string) ($snapshot['name_en'] ?? $snapshot['name_ar'] ?? $item['sku']), 0, 255),
                    'quantity' => (int) $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $item['line_total'],
                    'commission_rate' => $item['commission_rate_pct'],
                    'fulfillment_status' => $item['fulfillment_status'],
                    ...$this->timestamps($item),
                ];
            }

            $subs[(string) $sub['id']] = ['values' => [
                'vendor_id' => $vendorId,
                'number' => (string) $sub['sub_order_number'],
                'status' => $sub['status'],
                'subtotal' => $sub['subtotal'],
                'discount_total' => '0',
                'shipping_total' => $sub['shipping'],
                'shipping_method' => CatalogStep::SHIPPING_METHODS[$shippingMethod['code'] ?? ''] ?? null,
                'shipping_cost' => $sub['carrier_shipping_cost'],
                'shipping_subsidy_vendor' => $sub['vendor_contribution_amount'],
                'shipping_subsidy_platform' => $sub['admin_subsidy_amount'],
                'warranty_total' => $sub['warranty_revenue'],
                'total' => $total,
                'estimated_delivery_by' => $sub['estimated_delivery_date'],
                'delivered_at' => $sub['delivered_at'],
                'cod_remittance_confirmed' => (bool) $sub['cod_remittance_confirmed'],
                ...$this->timestamps($sub),
            ], 'items' => $items];
        }

        if ($subs === []) {
            return 'shape_unsupported: order without sub-orders';
        }

        $discount = bcadd(self::amount($row['discount'] ?? null), self::amount($row['loyalty_discount'] ?? null), 4);
        $expected = bcsub(bcadd($subTotals, self::amount($row['cod_fee'] ?? null), 4), $discount, 4);

        if (bccomp($expected, (string) $row['total'], 4) !== 0) {
            return "totals_mismatch: Σ sub-orders + COD fee − discounts = {$expected}, legacy total = {$row['total']}";
        }

        $history = [];

        foreach ($connection->table('order_status_histories')->where('order_id', $row['id'])->orderBy('created_at')->orderBy('id')->get() as $entry) {
            $adminUserId = $entry->changed_by_admin_id ? $this->ids->find('admins', (string) $entry->changed_by_admin_id, MigrationContext::CENTRAL, 'users') : null;
            $history[(string) $entry->id] = [
                'legacy_sub_order_id' => $entry->sub_order_id,
                'from_status' => $entry->from_status === null ? null : mb_substr((string) $entry->from_status, 0, 24),
                'to_status' => mb_substr((string) $entry->to_status, 0, 24),
                'actor_type' => $adminUserId === null ? 'system' : 'admin',
                'actor_user_id' => $adminUserId,
                'reason' => $entry->reason === null ? null : mb_substr((string) $entry->reason, 0, 255),
                'meta' => $entry->metadata,
                'created_at' => $entry->created_at ?? now(),
            ];
        }

        return ['order' => [
            'number' => (string) $row['order_number'],
            'customer_user_id' => $customerUserId,
            'status' => $row['status'],
            'payment_method' => $method,
            'payment_status' => $row['payment_status'],
            'currency' => $currency,
            'subtotal' => $row['subtotal'],
            'discount_total' => $discount,
            'shipping_total' => $row['shipping'],
            'cod_fee' => $row['cod_fee'],
            'warranty_total' => $row['warranty_total'],
            'grand_total' => $row['total'],
            'tax_included' => $row['tax'],
            'shipping_address' => json_decode((string) ($row['shipping_address_snapshot'] ?? '{}'), true) ?: [],
            'placed_at' => $row['placed_at'] ?? $row['created_at'],
            ...$this->timestamps($row),
        ], 'subs' => $subs, 'history' => $history];
    }

    /**
     * The migrated listing of an item: its vendor listing, else the vendor's
     * listing of the same variant in the order's country.
     *
     * @param  array<string, mixed>  $item
     */
    private function listingFor(array $item, string $code): ?int
    {
        if ($item['vendor_listing_id'] !== null) {
            return $this->ids->find('vendor_listings', (string) $item['vendor_listing_id'], $code, 'listings');
        }

        if ($item['vendor_id'] === null || $item['product_variant_id'] === null) {
            return null;
        }

        $listing = $this->source->connection()->table('vendor_listings')->where('vendor_id', $item['vendor_id'])->where('product_variant_id', $item['product_variant_id'])->value('id');

        return $listing === null ? null : $this->ids->find('vendor_listings', (string) $listing, $code, 'listings');
    }

    /**
     * A legacy decimal as a bcmath operand (NULL = 0).
     */
    private static function amount(mixed $value): string
    {
        return $value === null || $value === '' ? '0' : (string) $value;
    }

    private function quarantineChildren(string $orderId): void
    {
        foreach (['sub_orders', 'order_items', 'order_status_histories'] as $table) {
            if (! $this->source->hasTable($table)) {
                continue;
            }

            foreach ($this->source->connection()->table($table)->where('order_id', $orderId)->get() as $child) {
                $this->quarantine->put($table, (string) $child->id, 'parent_quarantined', "order {$orderId} is quarantined", null, (array) $child);
            }
        }
    }

    private function countMoved(string $table, string $column): void
    {
        foreach ($this->rows($table) as $row) {
            if (! $this->quarantine->has($table, (string) $row['id'])) {
                $this->context->count($table, 'moved_with_order');
            }
        }
    }
}
