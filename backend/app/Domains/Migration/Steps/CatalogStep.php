<?php

namespace App\Domains\Migration\Steps;

use App\Domains\Migration\Support\MigrationContext;
use App\Domains\Migration\Support\RedirectMap;
use Illuminate\Support\Str;

/**
 * Catalog and shipping reference (plan §3 step 3, MG3):
 * - categories and brands (R4) are replicated to every country DB, linked by
 *   slug when the country already has one (existing rows are never changed);
 *   category slugs are normalised to the storefront's URL segment
 *   (`[a-z0-9-]`, so legacy `smart_home` → `smart-home`, see {@see categorySlug()});
 * - legacy attributes + values + category_attributes fold into the new
 *   per-category attribute definitions (key, type, options);
 * - products, variants and images (R5) go to each country that has a listing
 *   of the product or an available product_countries row; variant
 *   attributes become `option_values`;
 * - warranty plans go to the countries in their `country_ids`;
 * - shipping zones (R1) and their rates by destination zone and method.
 */
class CatalogStep extends Step
{
    /**
     * Legacy shipping method code → new method (pickup / scheduled have none).
     *
     * @var array<string, string>
     */
    public const SHIPPING_METHODS = ['standard' => 'standard', 'express' => 'express', 'same_day' => 'same_day'];

    public function name(): string
    {
        return 'catalog';
    }

    protected function order(): array
    {
        return ['categories', 'brands', 'category_attributes', 'products', 'product_variants', 'product_images', 'warranty_plans', 'shipping_zones', 'shipping_rates'];
    }

    protected function migrateCategories(string $table): void
    {
        $rows = $this->rows($table)->all();
        usort($rows, fn (array $a, array $b): int => [(int) $a['depth'], (int) $a['lft'], $a['id']] <=> [(int) $b['depth'], (int) $b['lft'], $b['id']]);

        foreach ($this->context->targetCountries() as $code) {
            foreach ($rows as $row) {
                $id = (string) $row['id'];
                $parentId = $row['parent_id'] ? $this->ids->find($table, (string) $row['parent_id'], $code, 'categories') : null;

                if ($row['parent_id'] && $parentId === null) {
                    $this->quarantine->put($table, $id, 'parent_unmigrated', "parent category {$row['parent_id']} not migrated to {$code}", $code, $row);

                    continue;
                }

                $slug = self::categorySlug($row);

                $this->writer->upsert($table, $id, $code, 'categories', [
                    'parent_id' => $parentId,
                    'slug' => $this->writer->unique($code, 'categories', 'slug', $slug, $this->ids->find($table, $id, $code, 'categories')),
                    'name_en' => $this->bilingual($row, 'name', 'en'),
                    'name_ar' => $this->bilingual($row, 'name', 'ar'),
                    'description_en' => $row['description_en'],
                    'description_ar' => $row['description_ar'],
                    'commission_rate' => $row['commission_rate'],
                    'return_window_days' => $row['return_window_days'],
                    'requires_contract' => (bool) $row['requires_contract'],
                    'min_stock_for_campaign' => $row['min_stock_for_campaign'],
                    'platform_sample_qty' => $row['platform_sample_qty'],
                    'influencer_sample_qty' => $row['influencer_sample_qty'],
                    'affiliate_sample_qty' => $row['affiliate_sample_qty'],
                    'is_active' => (bool) $row['is_active'] && (bool) ($row['is_visible'] ?? true),
                    'sort_order' => max(0, (int) $row['sort_order']),
                    ...$this->timestamps($row),
                ], ['slug' => $slug]);
            }
        }
    }

    /**
     * The category's slug as a storefront URL segment (`/{market}/{slug}`
     * accepts `[a-z0-9-]` only): legacy underscores become hyphens, anything
     * else is slugged; the legacy slug is kept when it already fits. The old
     * URL is redirected ({@see RedirectMap}).
     *
     * @param  array<string, mixed>  $row
     */
    public static function categorySlug(array $row): string
    {
        $legacy = (string) $row['slug'];

        if (preg_match('/^[a-z0-9-]+$/', $legacy) === 1) {
            return $legacy;
        }

        $slug = Str::slug(str_replace('_', '-', $legacy));

        return $slug !== '' ? $slug : (Str::slug((string) ($row['name_en'] ?? '')) ?: 'category-'.substr(sha1($legacy), 0, 8));
    }

    protected function migrateBrands(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            foreach ($this->context->targetCountries() as $code) {
                $this->writer->upsert($table, (string) $row['id'], $code, 'brands', [
                    'slug' => (string) $row['slug'],
                    'name' => $this->bilingual($row, 'name', 'en'),
                    'name_ar' => $row['name_ar'],
                    'logo_path' => null,
                    'is_active' => (bool) $row['is_active'] && $row['deleted_at'] === null,
                    ...$this->timestamps($row),
                ], ['slug' => (string) $row['slug']]);
            }
        }
    }

    protected function migrateCategoryAttributes(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $attribute = $this->source->find('attributes', (string) $row['attribute_id']);

            if ($attribute === null) {
                $this->quarantine->put($table, (string) $row['id'], 'parent_missing', 'attribute not found', null, $row);

                continue;
            }

            $options = $this->source->connection()->table('attribute_values')->where('attribute_id', $attribute['id'])
                ->orderBy('sort_order')->orderBy('id')->get()->map(fn (object $value): string => (string) ($value->value_en ?: $value->value_ar))->unique()->values()->all();
            $type = match ($attribute['type']) {
                'number' => 'number',
                'boolean' => 'boolean',
                'select', 'multi_select', 'color' => 'select',
                default => 'text',
            };

            foreach ($this->context->targetCountries() as $code) {
                $categoryId = $this->ids->find('categories', (string) $row['category_id'], $code, 'categories');

                if ($categoryId === null) {
                    $this->quarantine->put($table, (string) $row['id'], 'parent_unmigrated', 'category not migrated', $code, $row);

                    continue;
                }

                $key = Str::limit(Str::slug((string) ($attribute['code'] ?: $attribute['name_en']), '_'), 48, '');
                $this->writer->upsert($table, (string) $row['id'], $code, 'category_attributes', [
                    'category_id' => $categoryId,
                    'key' => $key,
                    'label_en' => Str::limit($this->bilingual($attribute, 'name', 'en'), 120, ''),
                    'label_ar' => Str::limit($this->bilingual($attribute, 'name', 'ar'), 120, ''),
                    'type' => $type,
                    'options' => $type === 'select' ? $options : null,
                    'is_required' => (bool) $row['is_required'],
                    'is_filterable' => (bool) $attribute['is_filterable'],
                    'sort_order' => max(0, (int) $row['sort_order']),
                    ...$this->timestamps($row),
                ], ['category_id' => $categoryId, 'key' => $key]);
            }
        }
    }

    protected function migrateProducts(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];
            $resolution = $this->countries->resolveRow($table, $row);

            if ($resolution['codes'] === []) {
                $this->quarantine->put($table, $id, $this->reasonCode($resolution['reason']), $resolution['reason'], null, $row);

                continue;
            }

            foreach ($resolution['codes'] as $code) {
                if (! $this->context->allows($code)) {
                    $this->context->count($table, 'skipped_other_country');

                    continue;
                }

                $categoryId = $this->ids->find('categories', (string) $row['category_id'], $code, 'categories');

                if ($categoryId === null) {
                    $this->quarantine->put($table, $id, 'parent_unmigrated', "category {$row['category_id']} not migrated to {$code}", $code, $row);

                    continue;
                }

                $this->writer->upsert($table, $id, $code, 'products', [
                    'category_id' => $categoryId,
                    'brand_id' => $row['brand_id'] ? $this->ids->find('brands', (string) $row['brand_id'], $code, 'brands') : null,
                    'slug' => $this->writer->unique($code, 'products', 'slug', (string) $row['slug'], $this->ids->find($table, $id, $code, 'products')),
                    'title_en' => $this->bilingual($row, 'name', 'en'),
                    'title_ar' => $this->bilingual($row, 'name', 'ar'),
                    'model_number' => $row['model_number'] === null ? null : Str::limit((string) $row['model_number'], 64, ''),
                    'description_en' => $row['description_en'] ?? $row['short_desc_en'],
                    'description_ar' => $row['description_ar'] ?? $row['short_desc_ar'],
                    'status' => in_array($row['status'], ['draft', 'active', 'restricted', 'discontinued'], true) ? $row['status'] : 'draft',
                    'contract_requirement' => in_array($row['contract_requirement'], ['none', 'required', 'optional'], true) ? $row['contract_requirement'] : null,
                    ...$this->timestamps($row),
                ]);
            }
        }
    }

    protected function migrateProductVariants(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];
            $targets = $this->ids->targets('products', (string) $row['product_id'], 'products');

            if ($targets === []) {
                $this->quarantine->put($table, $id, 'parent_unmigrated', 'product not migrated', null, $row);

                continue;
            }

            $options = [];

            foreach ($this->source->connection()->table('product_variant_attributes')->where('product_variant_id', $id)->get() as $value) {
                $attribute = $this->source->find('attributes', (string) $value->attribute_id);
                $chosen = $value->attribute_value_id ? $this->source->find('attribute_values', (string) $value->attribute_value_id) : null;
                $key = Str::slug((string) ($attribute['code'] ?? $attribute['name_en'] ?? 'option'), '_');
                $options[$key] = $chosen !== null ? (string) ($chosen['value_en'] ?: $chosen['value_ar']) : (string) ($value->value_text_en ?? $value->value_number ?? $value->value_text_ar);
            }

            ksort($options);

            foreach ($targets as $code => $productId) {
                $this->writer->upsert($table, $id, $code, 'product_variants', [
                    'product_id' => $productId,
                    'sku' => $this->writer->unique($code, 'product_variants', 'sku', (string) $row['sku'], $this->ids->find($table, $id, $code, 'product_variants')),
                    'barcode' => $row['barcode'] === null ? null : Str::limit((string) $row['barcode'], 32, ''),
                    'option_values' => $options === [] ? null : $options,
                    'weight_grams' => $row['weight_grams'],
                    'length_cm' => $row['length_cm'],
                    'width_cm' => $row['width_cm'],
                    'height_cm' => $row['height_cm'],
                    ...$this->timestamps($row),
                ]);
            }
        }
    }

    protected function migrateProductImages(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $targets = $this->ids->targets('products', (string) $row['product_id'], 'products');

            if ($targets === []) {
                $this->quarantine->put($table, (string) $row['id'], 'parent_unmigrated', 'product not migrated', null, $row);

                continue;
            }

            foreach ($targets as $code => $productId) {
                $this->writer->upsert($table, (string) $row['id'], $code, 'product_media', [
                    'product_id' => $productId,
                    'product_variant_id' => $row['product_variant_id'] ? $this->ids->find('product_variants', (string) $row['product_variant_id'], $code, 'product_variants') : null,
                    'path' => 'legacy/'.ltrim((string) $row['path'], '/'),
                    'alt_en' => $row['alt_text_en'],
                    'alt_ar' => $row['alt_text_ar'],
                    'sort_order' => (int) $row['position'],
                    ...$this->timestamps($row),
                ]);
            }
        }
    }

    protected function migrateWarrantyPlans(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];

            if ($row['price_type'] !== 'flat') {
                $this->quarantine->put($table, $id, 'shape_unsupported', 'percentage-priced warranty plans have no new equivalent', null, $row);

                continue;
            }

            $codes = array_values(array_filter(array_map(fn ($countryId): ?string => $this->countries->code((string) $countryId), (array) json_decode((string) $row['country_ids'], true))));

            if ($codes === []) {
                $this->quarantine->put($table, $id, 'country_unresolved', 'country_ids lists no launched country', null, $row);

                continue;
            }

            foreach ($codes as $code) {
                if (! $this->context->allows($code)) {
                    continue;
                }

                if ($row['currency'] !== null && strtoupper((string) $row['currency']) !== $this->countries->currencyOf($code)) {
                    $this->quarantine->put($table, $id, 'currency_mismatch', "price in {$row['currency']}, {$code} uses ".$this->countries->currencyOf($code), $code, $row);

                    continue;
                }

                $categoryId = $this->ids->find('categories', (string) $row['category_id'], $code, 'categories');

                if ($categoryId === null) {
                    $this->quarantine->put($table, $id, 'parent_unmigrated', 'category not migrated', $code, $row);

                    continue;
                }

                $this->writer->upsert($table, $id, $code, 'warranty_plans', [
                    'category_id' => $categoryId,
                    'name_en' => $this->bilingual($row, 'name', 'en'),
                    'name_ar' => $this->bilingual($row, 'name', 'ar'),
                    'duration_months' => (int) $row['duration_months'],
                    'price' => $row['price'],
                    'is_active' => (bool) $row['is_active'],
                    'sort_order' => (int) $row['sort_order'],
                    ...$this->timestamps($row),
                ]);
                $this->context->sum($table, $this->countries->currencyOf($code), $row['price']);
            }
        }
    }

    protected function migrateShippingZones(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $code = $this->singleCountry($table, $row);

            if ($code === null) {
                continue;
            }

            $this->writer->upsert($table, (string) $row['id'], $code, 'shipping_zones', [
                'name' => (string) $row['name'],
                'is_default' => false,
                'is_exceptional' => false,
                'is_active' => (bool) $row['is_active'] && $row['deleted_at'] === null,
                ...$this->timestamps($row),
            ]);
        }
    }

    protected function migrateShippingRates(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];

            if ($row['origin_zone_id'] !== null && $row['origin_zone_id'] !== $row['destination_zone_id']) {
                $this->quarantine->put($table, $id, 'shape_unsupported', 'origin-to-destination rate; new rates are per destination zone', null, $row);

                continue;
            }

            $zone = $this->source->find('shipping_zones', (string) $row['destination_zone_id']);
            $code = $zone === null ? null : $this->countries->code((string) $zone['country_id']);
            $zoneId = $code === null ? null : $this->ids->find('shipping_zones', (string) $row['destination_zone_id'], $code, 'shipping_zones');
            $method = $this->source->find('shipping_methods', (string) $row['shipping_method_id']);
            $newMethod = self::SHIPPING_METHODS[$method['code'] ?? ''] ?? null;

            if ($code !== null && ! $this->context->allows($code)) {
                $this->context->count($table, 'skipped_other_country');

                continue;
            }

            if ($zoneId === null) {
                $this->quarantine->put($table, $id, 'parent_unmigrated', 'destination zone not migrated', $code, $row);

                continue;
            }

            if ($newMethod === null) {
                $this->quarantine->put($table, $id, 'method_unmapped', 'shipping method '.($method['code'] ?? '?').' has no new equivalent', $code, $row);

                continue;
            }

            $taken = $this->ids->find($table, $id, $code, 'shipping_rates') === null
                && MigrationContext::db($code)->table('shipping_rates')->where('shipping_zone_id', $zoneId)->where('method', $newMethod)->exists();

            if ($taken) {
                $this->quarantine->put($table, $id, 'duplicate_rate', "zone already has a {$newMethod} rate", $code, $row);

                continue;
            }

            $this->writer->upsert($table, $id, $code, 'shipping_rates', [
                'shipping_zone_id' => $zoneId,
                'method' => $newMethod,
                'base_fee' => $row['base_fee'],
                'base_weight_kg' => $row['min_weight_grams'] === null ? null : bcdiv((string) $row['min_weight_grams'], '1000', 3),
                'per_kg_fee' => $row['rate_per_kg'],
                'transit_min_days' => $method['min_delivery_days'] ?? null,
                'transit_max_days' => $method['max_delivery_days'] ?? null,
                'cutoff_time' => $method['order_cutoff_time'] ?? null,
                'is_active' => (bool) $row['is_active'],
                ...$this->timestamps($row),
            ]);
            $this->context->sum($table, $this->countries->currencyOf($code), $row['base_fee']);
        }
    }
}
