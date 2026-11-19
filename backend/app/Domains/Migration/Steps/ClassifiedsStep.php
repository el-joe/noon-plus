<?php

namespace App\Domains\Migration\Steps;

use App\Domains\Migration\Support\MigrationContext;
use Illuminate\Support\Str;

/**
 * Classifieds (MG7). Categories are reference data (R4): replicated to
 * every country and linked by slug to an existing category, never
 * overwritten. Listings (R1) become classified ads of their owner — a
 * customer, a store (owner = the store's owner, `vendor_id` set) or a
 * marketer (`marketer_profile_id` set); the price is copied in the ad's
 * currency (which must be the country's). Sale / rent and the legacy
 * attribute values go into `specs`. Soft-deleted listings are archived;
 * contract signature data, sketches and barcodes stay in `legacy_records`.
 */
class ClassifiedsStep extends Step
{
    /**
     * Legacy seller morph type → legacy identity table.
     *
     * @var array<string, string>
     */
    public const SELLERS = ['App\\Models\\Customer' => 'customers', 'App\\Models\\Vendor' => 'vendors', 'App\\Models\\Marketer' => 'marketers', 'customer' => 'customers', 'vendor' => 'vendors', 'marketer' => 'marketers'];

    public function name(): string
    {
        return 'classifieds';
    }

    protected function order(): array
    {
        return ['classified_categories', 'classified_listings'];
    }

    protected function migrateClassifiedCategories(string $table): void
    {
        $rows = $this->rows($table)->keyBy(fn (array $row): string => (string) $row['id'])->all();
        $depth = function (array $row, int $level = 0) use (&$depth, $rows): int {
            return $row['parent_id'] === null || ! isset($rows[$row['parent_id']]) || $level > 10 ? $level : $depth($rows[$row['parent_id']], $level + 1);
        };
        uasort($rows, fn (array $a, array $b): int => [$depth($a), (int) $a['sort_order'], $a['id']] <=> [$depth($b), (int) $b['sort_order'], $b['id']]);

        foreach ($this->context->targetCountries() as $code) {
            foreach ($rows as $id => $row) {
                $parentId = $row['parent_id'] ? $this->ids->find($table, (string) $row['parent_id'], $code, 'classified_categories') : null;

                if ($row['parent_id'] && $parentId === null) {
                    $this->quarantine->put($table, (string) $id, 'parent_unmigrated', "parent category {$row['parent_id']} not migrated to {$code}", $code, $row);

                    continue;
                }

                $slug = Str::slug(str_replace('_', '-', (string) $row['slug'])) ?: 'classified-'.substr(sha1((string) $id), 0, 8);

                $this->writer->upsert($table, (string) $id, $code, 'classified_categories', [
                    'parent_id' => $parentId,
                    'slug' => $this->writer->unique($code, 'classified_categories', 'slug', $slug, $this->ids->find($table, (string) $id, $code, 'classified_categories'), [], 120),
                    'name_en' => $this->bilingual($row, 'name', 'en'),
                    'name_ar' => $this->bilingual($row, 'name', 'ar'),
                    'requires_contract' => (bool) $row['requires_contract'],
                    'is_active' => (bool) $row['is_active'],
                    'sort_order' => max(0, (int) $row['sort_order']),
                    ...$this->timestamps($row),
                ], ['slug' => $slug]);
            }
        }
    }

    protected function migrateClassifiedListings(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];

            if ($row['deleted_at'] !== null) {
                $this->archiveRow($table, $row, 'R1', $this->countries->resolveRow($table, $row), 'soft_deleted');

                continue;
            }

            $code = $this->singleCountry($table, $row);

            if ($code === null) {
                continue;
            }

            $plan = $this->plan($row, $code);

            if (is_string($plan)) {
                $this->quarantine->put($table, $id, strtok($plan, ':') ?: 'unresolved', $plan, $code, $row);

                continue;
            }

            $this->writer->upsert($table, $id, $code, 'classified_ads', $plan);

            if ($row['contract_signature_data'] !== null || $row['sketch_file_path'] !== null || $row['barcode_path'] !== null || $row['sold_via_campaign_id'] !== null) {
                $this->keepLegacyRow($table, $id, $row, $code);
            }

            $this->context->sum($table, $row['currency'], (string) $row['price']);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|string
     */
    private function plan(array $row, string $code): array|string
    {
        $currency = strtoupper((string) $row['currency']);

        if ($currency !== $this->countries->currencyOf($code)) {
            return "currency_mismatch: ad priced in {$currency}";
        }

        $categoryId = $this->ids->find('classified_categories', (string) $row['classified_category_id'], $code, 'classified_categories');

        if ($categoryId === null) {
            return 'parent_unmigrated: classified category not migrated';
        }

        $sellerTable = self::SELLERS[(string) $row['seller_type']] ?? null;
        $vendorId = null;
        $marketerProfileId = null;

        if ($sellerTable === 'vendors') {
            $vendorId = $this->ids->find('vendors', (string) $row['seller_id'], $code, 'vendors');
            $ownerUserId = $vendorId === null ? null : ($vendorId > 0 ? (int) MigrationContext::db($code)->table('vendors')->where('id', $vendorId)->value('owner_user_id') : $vendorId);
        } elseif ($sellerTable === 'marketers') {
            $marketerProfileId = $this->ids->find('marketers', (string) $row['seller_id'], $code, 'marketer_profiles');
            $ownerUserId = $this->userOf('marketers', (string) $row['seller_id']);
        } else {
            $ownerUserId = $sellerTable === null ? null : $this->userOf($sellerTable, (string) $row['seller_id']);
        }

        if ($ownerUserId === null || ($sellerTable === 'marketers' && $marketerProfileId === null)) {
            return "owner_unresolved: seller {$row['seller_type']} {$row['seller_id']} not migrated to {$code}";
        }

        if (mb_strlen((string) $row['listing_number']) > 24) {
            return "shape_unsupported: listing number {$row['listing_number']} is longer than 24 characters";
        }

        $city = $row['city_id'] !== null && $this->source->hasTable('cities') ? ($this->source->find('cities', (string) $row['city_id'])['name_en'] ?? null) : null;
        $specs = (array) json_decode((string) ($row['attributes'] ?? '[]'), true);
        $specs['purpose'] = $row['listing_purpose'];

        if ($row['title_ar'] !== null && $row['title_ar'] !== '' && $row['title_ar'] !== $row['title_en']) {
            $specs['title_ar'] = $row['title_ar'];
        }

        if ($row['description_ar'] !== null && $row['description_ar'] !== '' && $row['description_en'] !== null && $row['description_en'] !== '') {
            $specs['description_ar'] = $row['description_ar'];
        }

        $own = $this->ids->find('classified_listings', (string) $row['id'], $code, 'classified_ads');

        return [
            'number' => $this->writer->unique($code, 'classified_ads', 'number', (string) $row['listing_number'], $own, [], 24),
            'classified_category_id' => $categoryId,
            'owner_user_id' => $ownerUserId,
            'vendor_id' => $vendorId,
            'marketer_profile_id' => $marketerProfileId,
            'kind' => 'offer',
            'title' => mb_substr($this->bilingual($row, 'title', 'en'), 0, 255),
            'description' => $row['description_en'] ?: $row['description_ar'],
            'currency' => $currency,
            'price' => $row['price'],
            'is_negotiable' => (bool) $row['price_negotiable'],
            'city' => $city === null ? null : mb_substr((string) $city, 0, 80),
            'latitude' => $row['latitude'],
            'longitude' => $row['longitude'],
            'specs' => $specs,
            'status' => $row['status'],
            'rejection_reason' => $row['rejection_reason'] === null ? null : mb_substr((string) $row['rejection_reason'], 0, 255),
            'approved_at' => $row['approved_at'],
            'expires_at' => $row['expires_at'],
            'sold_price' => $row['sold_price'],
            'sold_buyer_note' => $row['sold_buyer_note'] === null ? null : mb_substr((string) $row['sold_buyer_note'], 0, 255),
            'sold_at' => $row['sold_at'],
            'views' => max(0, (int) $row['views_count']),
            ...$this->timestamps($row),
        ];
    }
}
