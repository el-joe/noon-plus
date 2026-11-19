<?php

namespace App\Domains\Migration\Steps;

/**
 * Vendor listings (MG3, rule R1). The legacy selling price becomes the
 * sale price when a compare-at ("was") price exists, which becomes the
 * regular price — both copied, never recomputed. Like-new / good /
 * acceptable conditions fold into the new "used" grade; the price must be
 * in the country's currency (never converted).
 */
class ListingsStep extends Step
{
    /**
     * @var array<string, string>
     */
    public const CONDITIONS = ['new' => 'new', 'refurbished' => 'refurbished', 'like_new' => 'used', 'good' => 'used', 'acceptable' => 'used'];

    public function name(): string
    {
        return 'listings';
    }

    protected function migrateVendorListings(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];
            $code = $this->singleCountry($table, $row);

            if ($code === null) {
                continue;
            }

            if (strtoupper((string) $row['currency']) !== $this->countries->currencyOf($code)) {
                $this->quarantine->put($table, $id, 'currency_mismatch', "listing in {$row['currency']}, {$code} sells in ".$this->countries->currencyOf($code), $code, $row);

                continue;
            }

            $vendorId = $this->ids->find('vendors', (string) $row['vendor_id'], $code, 'vendors');
            $variantId = $this->ids->find('product_variants', (string) $row['product_variant_id'], $code, 'product_variants');

            if ($vendorId === null || $variantId === null) {
                $this->quarantine->put($table, $id, 'parent_unmigrated', $vendorId === null ? 'vendor not migrated' : 'product variant not migrated', $code, $row);

                continue;
            }

            $wasPrice = $row['compare_at_price'] !== null && bccomp((string) $row['compare_at_price'], (string) $row['price'], 4) > 0;

            $this->writer->upsert($table, $id, $code, 'listings', [
                'vendor_id' => $vendorId,
                'product_variant_id' => $variantId,
                'vendor_sku' => $row['vendor_sku'] === null ? null : mb_substr((string) $row['vendor_sku'], 0, 64),
                'status' => $row['deleted_at'] !== null ? 'archived' : $row['status'],
                'price' => $wasPrice ? $row['compare_at_price'] : $row['price'],
                'sale_price' => $wasPrice ? $row['price'] : null,
                'fulfilment' => $row['fulfillment_model'],
                'condition' => self::CONDITIONS[$row['condition']] ?? 'new',
                'rejection_reason' => $row['rejection_reason'] === null ? null : mb_substr((string) $row['rejection_reason'], 0, 255),
                ...$this->timestamps($row),
            ], ['vendor_id' => $vendorId, 'product_variant_id' => $variantId, 'condition' => self::CONDITIONS[$row['condition']] ?? 'new']);
            $this->context->sum($table, $row['currency'], $row['price']);
        }
    }
}
