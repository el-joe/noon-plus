<?php

namespace App\Domains\Migration\Support;

/**
 * Country split rules R1–R3 (plan §4): the country of a legacy row from its
 * own country column, its parent row, or its (polymorphic) owner. Returns
 * the country codes the row belongs to; an empty list with a reason when it
 * cannot be resolved (the caller quarantines it). Owners active in several
 * countries (a vendor selling in two) resolve to several codes.
 */
class CountryResolver
{
    /**
     * Legacy morph types (and short role names) → legacy table.
     *
     * @var array<string, string>
     */
    public const MORPHS = [
        'App\\Models\\Customer' => 'customers', 'customer' => 'customers',
        'App\\Models\\Vendor' => 'vendors', 'vendor' => 'vendors',
        'App\\Models\\VendorAdmin' => 'vendor_admins', 'seller' => 'vendor_admins',
        'App\\Models\\Marketer' => 'marketers', 'marketer' => 'marketers',
        'App\\Models\\MarketerAdmin' => 'marketer_admins',
        'App\\Models\\DeliveryAgent' => 'delivery_agents', 'delivery_agent' => 'delivery_agents',
        'App\\Models\\TravelAgency' => 'travel_agencies', 'travel_agency' => 'travel_agencies',
        'App\\Models\\TravelAgencyMember' => 'travel_agency_members',
        'App\\Models\\ShippingCompanySupervisor' => 'shipping_company_supervisors', 'shipping_supervisor' => 'shipping_company_supervisors',
        'App\\Models\\ShippingCompany' => 'shipping_companies',
        'App\\Models\\Warehouse' => 'warehouses',
        'App\\Models\\Product' => 'products',
        'App\\Models\\VendorListing' => 'vendor_listings',
        'App\\Models\\Order' => 'orders',
        'App\\Models\\Admin' => 'admins', 'admin' => 'admins',
    ];

    /**
     * @var array<string, string|null>
     */
    private ?array $legacyCountries = null;

    /**
     * @var array<string, array{codes: array<int, string>, reason: string|null}>
     */
    private array $memo = [];

    public function __construct(private LegacySource $source) {}

    /**
     * New country code of a legacy `countries` row (matched by site code), or
     * a code / iso2 / iso3 string as some legacy tables store it.
     */
    public function code(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $codes = MigrationContext::allCountries();

        if (in_array(strtolower($value), $codes, true)) {
            return strtolower($value);
        }

        if ($this->legacyCountries === null) {
            $this->legacyCountries = [];

            foreach ($this->source->rows('countries') as $country) {
                $code = strtolower((string) $country['site_code']);
                $this->legacyCountries[(string) $country['id']] = in_array($code, $codes, true) ? $code : null;
            }
        }

        if (array_key_exists($value, $this->legacyCountries)) {
            return $this->legacyCountries[$value];
        }

        foreach (config('nawy.countries') as $code => $country) {
            if (strtoupper($value) === $country['iso2']) {
                return $code;
            }
        }

        return null;
    }

    /**
     * The country whose currency this is (each launched country has its own).
     */
    public function codeForCurrency(?string $currency): ?string
    {
        foreach (config('nawy.countries') as $code => $country) {
            if ($currency !== null && strtoupper($currency) === $country['currency']) {
                return $code;
            }
        }

        return null;
    }

    public function currencyOf(string $code): string
    {
        return config("nawy.countries.{$code}.currency");
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{codes: array<int, string>, reason: string|null}
     */
    public function resolve(string $table, array $row, int $depth = 0): array
    {
        $rule = TableRules::for($table);

        if ($depth > 6) {
            return $this->fail('country_unresolved', 'parent chain too deep');
        }

        $owner = $this->ownerCountries($table, $row);

        if ($owner !== null) {
            return $owner;
        }

        if ($rule === null || $rule['scope'] === 'central') {
            return ['codes' => [], 'reason' => 'central'];
        }

        if (isset($rule['country_column'])) {
            $value = $row[$rule['country_column']] ?? null;

            if (($value === null || $value === '' || $value === '*') && $this->codeForCurrency($row['currency'] ?? $row['currency_code'] ?? null) === null) {
                return ['codes' => [], 'reason' => 'central'];
            }

            $code = $this->code($value === null ? null : (string) $value);

            if ($code !== null) {
                return ['codes' => [$code], 'reason' => null];
            }

            $byCurrency = $this->codeForCurrency($row['currency'] ?? $row['currency_code'] ?? null);

            return $byCurrency !== null && ($row[$rule['country_column']] ?? null) === null
                ? ['codes' => [$byCurrency], 'reason' => null]
                : $this->fail('country_unresolved', "{$rule['country_column']} is empty or not a launched country");
        }

        if (isset($rule['via'])) {
            $parentId = $row[$rule['via']] ?? null;
            $parent = $this->source->find($rule['parent'], $parentId === null ? null : (string) $parentId);

            if ($parent === null) {
                return $this->fail('parent_missing', "{$rule['via']} → {$rule['parent']} not found");
            }

            return $this->resolveRow($rule['parent'], $parent, $depth + 1);
        }

        if (isset($rule['morph'])) {
            [$typeColumn, $idColumn] = $rule['morph'];
            $ownerTable = self::MORPHS[(string) ($row[$typeColumn] ?? '')] ?? null;
            $ownerRow = $ownerTable === null ? null : $this->source->find($ownerTable, isset($row[$idColumn]) ? (string) $row[$idColumn] : null);

            if ($ownerRow === null) {
                return $this->fail('owner_unresolved', "{$typeColumn} ".($row[$typeColumn] ?? 'null').' not resolved');
            }

            $owner = $this->resolveRow($ownerTable, $ownerRow, $depth + 1);

            // Wallets and other money owned per currency belong to the currency's country (R3).
            $byCurrency = $this->codeForCurrency($row['currency'] ?? $row['currency_code'] ?? null);

            if ($byCurrency !== null && ($owner['codes'] === [] || in_array($byCurrency, $owner['codes'], true) || count($owner['codes']) > 1)) {
                return ['codes' => [$byCurrency], 'reason' => null];
            }

            return $owner;
        }

        return $this->fail('country_unresolved', 'no country rule');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{codes: array<int, string>, reason: string|null}
     */
    public function resolveRow(string $table, array $row, int $depth = 0): array
    {
        $key = $table.'|'.($row['id'] ?? sha1(serialize($row)));

        return $this->memo[$key] ??= $this->resolve($table, $row, $depth);
    }

    /**
     * Owners (identity tables) and R5 products have their own rules.
     *
     * @param  array<string, mixed>  $row
     * @return array{codes: array<int, string>, reason: string|null}|null
     */
    private function ownerCountries(string $table, array $row): ?array
    {
        return match ($table) {
            'admins' => ['codes' => [], 'reason' => 'central'],
            'customers', 'marketers', 'travel_agencies', 'delivery_agents', 'shipping_companies', 'shipping_company_supervisors', 'warehouses', 'orders' => $this->direct($row),
            'vendors' => $this->vendorCountries($row),
            'vendor_admins' => $this->via('vendors', $row['vendor_id'] ?? null),
            'marketer_admins', 'marketer_profiles' => $this->via('marketers', $row['marketer_id'] ?? null),
            'travel_agency_members' => $this->via('travel_agencies', $row['travel_agency_id'] ?? null),
            'products' => $this->productCountries($row),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{codes: array<int, string>, reason: string|null}
     */
    private function direct(array $row): array
    {
        $code = $this->code(isset($row['country_id']) ? (string) $row['country_id'] : null);

        return $code === null ? $this->fail('country_unresolved', 'country_id is empty or not a launched country') : ['codes' => [$code], 'reason' => null];
    }

    /**
     * @return array{codes: array<int, string>, reason: string|null}
     */
    private function via(string $table, mixed $id): array
    {
        $parent = $this->source->find($table, $id === null ? null : (string) $id);

        return $parent === null ? $this->fail('parent_missing', "{$table} {$id} not found") : $this->resolveRow($table, $parent);
    }

    /**
     * A vendor's own country, plus every country it lists in (plan §4.2).
     *
     * @param  array<string, mixed>  $row
     * @return array{codes: array<int, string>, reason: string|null}
     */
    private function vendorCountries(array $row): array
    {
        $codes = [];
        $own = $this->code(isset($row['country_id']) ? (string) $row['country_id'] : null);

        if ($own !== null) {
            $codes[] = $own;
        }

        if ($this->source->hasTable('vendor_listings')) {
            foreach ($this->source->connection()->table('vendor_listings')->where('vendor_id', $row['id'])->distinct()->pluck('country_id') as $countryId) {
                $code = $this->code($countryId === null ? null : (string) $countryId);

                if ($code !== null && ! in_array($code, $codes, true)) {
                    $codes[] = $code;
                }
            }
        }

        return $codes === [] ? $this->fail('country_unresolved', 'vendor has no country and no listing in a launched country') : ['codes' => $codes, 'reason' => null];
    }

    /**
     * R5: a global product goes to every country with a listing of it or an
     * available per-country product row.
     *
     * @param  array<string, mixed>  $row
     * @return array{codes: array<int, string>, reason: string|null}
     */
    private function productCountries(array $row): array
    {
        $connection = $this->source->connection();
        $countryIds = [];

        if ($this->source->hasTable('product_countries')) {
            $countryIds = $connection->table('product_countries')->where('product_id', $row['id'])->where('is_available', 1)->pluck('country_id')->all();
        }

        if ($this->source->hasTable('vendor_listings')) {
            $variantIds = $connection->table('product_variants')->where('product_id', $row['id'])->pluck('id');
            $countryIds = array_merge($countryIds, $connection->table('vendor_listings')->whereIn('product_variant_id', $variantIds)->pluck('country_id')->all());
        }

        $codes = array_values(array_unique(array_filter(array_map(fn ($id): ?string => $this->code($id === null ? null : (string) $id), $countryIds))));
        sort($codes);

        return $codes === [] ? $this->fail('country_unresolved', 'product is not available or listed in any launched country') : ['codes' => $codes, 'reason' => null];
    }

    /**
     * @return array{codes: array<int, string>, reason: string}
     */
    private function fail(string $reason, string $detail): array
    {
        return ['codes' => [], 'reason' => $reason.': '.$detail];
    }
}
