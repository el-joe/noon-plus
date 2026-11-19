<?php

namespace App\Domains\Catalog\Support\DevCatalog;

use App\Domains\Catalog\Http\Controllers\CatalogController;
use App\Domains\Content\Support\BlockProducts;
use App\Domains\Contracts\Enums\SignatureMethod;
use App\Domains\Contracts\Enums\SignerType;
use App\Domains\Contracts\Support\ContractGates;
use App\Domains\Contracts\Support\ContractSigner;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Panels\Support\PanelCountries;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use App\Shared\Http\PublicCache;
use Carbon\CarbonImmutable;
use Database\Seeders\FbnDevSeeder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Development "big catalogue" of one country (nawy:dev:big-catalog):
 * vendors (signed seller agreement), the category tree with images,
 * fictional brands, products with variants, media (the 10 reused demo
 * images, stored once under products/demo/), 1–6 offers per variant from
 * different stores (FBN / marketplace, delivery speed, deals, price
 * drops, renewed grades), a live flash sale, the design's coupons and
 * sponsored placements.
 *
 * Deterministic and idempotent: every row has a stable natural key
 * (product slug + model number `BC-nnnnn`, variant SKU, vendor slug,
 * listing vendor × variant × condition, coupon code, flash-sale slug), so a
 * rerun updates instead of duplicating. Bulk upserts in chunks. Never
 * touches legacy-migrated or e2e rows.
 */
class BigCatalogSeeder
{
    public const MODEL_PREFIX = 'BC-';

    public const FLASH_SALE_SLUG = 'big-mega-deals';

    private const CHUNK = 400;

    /** @var array<string, string> */
    private const DIAL = ['uae' => '+9715', 'omn' => '+9689', 'ksa' => '+9665', 'egy' => '+2010', 'kwt' => '+9656', 'qat' => '+9745', 'bhr' => '+9733', 'jor' => '+9627'];

    private ConnectionInterface $db;

    private Country $country;

    private CarbonImmutable $now;

    /** @var array<string, array{0: int, 1: int}> image key => [width, height] */
    private array $imageSizes = [];

    /** @var callable(string): void */
    private $log;

    public function __construct(private CountryContext $context) {}

    /**
     * @param  callable(string): void|null  $log
     * @return array{products: int, variants: int, offers: int, vendors: int, media: int, categories: int, brands: int}
     */
    public function seed(Country $country, int $productCount, int $vendorCount, int $seed, ?callable $log = null): array
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The big catalogue is for development databases only.');
        }

        $this->log = $log ?? fn (string $line) => null;
        $this->now = CarbonImmutable::now();

        return $this->context->runAs($country, function (Country $country) use ($productCount, $vendorCount, $seed): array {
            $this->country = $country;
            $this->db = DB::connection($this->context->connectionName());

            $this->copyImages();
            $leaves = $this->categories();
            $brands = $this->brandRows();
            $vendors = $this->vendors($vendorCount);
            $warehouseId = $this->warehouse();
            $totals = $this->products($productCount, $seed, $leaves, $brands, $vendors, $warehouseId);
            $this->flashSale();
            $this->coupons();
            $this->ads($vendors);
            $this->forgetCaches();

            return $totals + [
                'vendors' => count($vendors),
                'categories' => count($leaves),
                'brands' => count($brands),
            ];
        });
    }

    /** Copy the 10 demo images to the public disk once (never per product). */
    private function copyImages(): void
    {
        $disk = Storage::disk('public');
        $source = base_path('../website/public/img');

        foreach (BigCatalogBlueprint::IMAGES as $key => $file) {
            $target = BigCatalogBlueprint::IMAGE_DIR.'/'.$file;

            if (! $disk->exists($target)) {
                if (! is_file("{$source}/{$file}")) {
                    throw new RuntimeException("Demo image {$file} is missing in website/public/img.");
                }

                $disk->put($target, (string) file_get_contents("{$source}/{$file}"));
            }

            $size = @getimagesize($disk->path($target)) ?: [800, 800];
            $this->imageSizes[$key] = [min(65535, (int) $size[0]), min(65535, (int) $size[1])];
        }
    }

    private function imagePath(string $key): string
    {
        return BigCatalogBlueprint::IMAGE_DIR.'/'.BigCatalogBlueprint::IMAGES[$key];
    }

    /**
     * Make sure the tree exists (existing slugs reused where they are,
     * missing ones created) and every category in it has an image.
     *
     * @return array<int, array{id: int, root: string, l2: string, slug: string, archetype: string, weight: float}>
     */
    private function categories(): array
    {
        $leaves = [];
        $rootSort = 0;
        $archetypes = BigCatalogBlueprint::archetypes();

        foreach (BigCatalogBlueprint::tree() as $rootSlug => [$rootEn, $rootAr, $rootImage, $weight, $children]) {
            $rootId = $this->category($rootSlug, null, $rootEn, $rootAr, $rootImage, $rootSort++);
            $leafCount = array_sum(array_map(fn (array $child): int => count($child[2]), $children));
            $l2Sort = 0;

            foreach ($children as $l2Slug => [$l2En, $l2Ar, $grandChildren]) {
                $firstArchetype = $archetypes[reset($grandChildren)[2]];
                $l2Id = $this->category($l2Slug, $rootId, $l2En, $l2Ar, $firstArchetype[0], $l2Sort++);
                $l3Sort = 0;

                foreach ($grandChildren as $l3Slug => [$l3En, $l3Ar, $archetype]) {
                    $l3Id = $this->category($l3Slug, $l2Id, $l3En, $l3Ar, $archetypes[$archetype][0], $l3Sort++);
                    $leaves[] = ['id' => $l3Id, 'root' => $rootSlug, 'l2' => $l2Slug, 'slug' => $l3Slug, 'archetype' => $archetype, 'weight' => $weight / max(1, $leafCount)];
                }
            }
        }

        // Any other category without an image (legacy / older dev rows) gets the root's.
        $this->db->table('categories')->whereNull('image_path')->update(['image_path' => $this->imagePath('flatlay')]);

        return $leaves;
    }

    private function category(string $slug, ?int $parentId, string $en, string $ar, string $image, int $sort): int
    {
        $row = $this->db->table('categories')->where('slug', $slug)->first(['id', 'image_path', 'is_active']);

        if ($row !== null) {
            $changes = [];

            if ($row->image_path === null || str_starts_with((string) $row->image_path, BigCatalogBlueprint::IMAGE_DIR.'/')) {
                $changes['image_path'] = $this->imagePath($image);
            }

            if (! $row->is_active) {
                $changes['is_active'] = true;
            }

            if ($changes !== []) {
                $this->db->table('categories')->where('id', $row->id)->update($changes + ['updated_at' => $this->now]);
            }

            return (int) $row->id;
        }

        return (int) $this->db->table('categories')->insertGetId([
            'parent_id' => $parentId, 'slug' => $slug, 'name_en' => $en, 'name_ar' => $ar,
            'description_en' => "Shop {$en} online with fast delivery.", 'description_ar' => "تسوّق {$ar} أونلاين مع توصيل سريع.",
            'image_path' => $this->imagePath($image), 'is_active' => true, 'sort_order' => $sort,
            'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
    }

    /**
     * @return array<string, array<int, array{id: int, slug: string, name: string, name_ar: string}>> family => brands
     */
    private function brandRows(): array
    {
        $byFamily = [];

        foreach (BigCatalogBlueprint::brands() as $family => $brands) {
            foreach ($brands as $slug => [$name, $nameAr]) {
                $this->db->table('brands')->updateOrInsert(['slug' => $slug], ['name' => $name, 'name_ar' => $nameAr, 'is_active' => true, 'updated_at' => $this->now]);
                $this->db->table('brands')->where('slug', $slug)->whereNull('created_at')->update(['created_at' => $this->now]);
                $byFamily[$family][] = ['id' => (int) $this->db->table('brands')->where('slug', $slug)->value('id'), 'slug' => $slug, 'name' => $name, 'name_ar' => $nameAr];
            }
        }

        return $byFamily;
    }

    /**
     * Stores: the official brand stores first, then local-flavoured names.
     * Each has an owner account (central DB), a vendor membership and the
     * seller agreement signed.
     *
     * @return array<int, array{id: int, slug: string, family: string, brand: string|null, rating: float}>
     */
    private function vendors(int $count): array
    {
        $code = $this->country->code;
        $places = BigCatalogBlueprint::places()[$code] ?? BigCatalogBlueprint::places()['uae'];
        $brandNames = [];

        foreach (BigCatalogBlueprint::brands() as $family => $brands) {
            foreach ($brands as $slug => [$name, $nameAr]) {
                $brandNames[$slug] ??= [$name, $nameAr, $family];
            }
        }

        $specs = [];

        foreach (BigCatalogBlueprint::OFFICIAL_STORES as $brandSlug) {
            [$name, $nameAr, $family] = $brandNames[$brandSlug];
            $specs[] = ['slug' => "{$brandSlug}-official", 'name' => "{$name} Official Store", 'name_ar' => "متجر {$nameAr} الرسمي", 'family' => $family, 'brand' => $brandSlug];
        }

        $kinds = BigCatalogBlueprint::STORE_KINDS;

        for ($i = 0; count($specs) < $count; $i++) {
            [$family, $kindEn, $kindAr] = $kinds[$i % count($kinds)];
            [$placeEn, $placeAr] = $places[($i + intdiv($i, count($kinds)) * 3) % count($places)];
            $suffix = $i >= count($kinds) * count($places) ? ' '.(intdiv($i, count($kinds) * count($places)) + 1) : '';
            $specs[] = ['slug' => Str::slug("{$placeEn} {$kindEn}{$suffix}"), 'name' => "{$placeEn} {$kindEn}{$suffix}", 'name_ar' => "{$placeAr} {$kindAr}{$suffix}", 'family' => $family, 'brand' => null];
        }

        $specs = array_slice($specs, 0, $count);
        $password = Hash::make(Str::random(40));
        $gates = app(ContractGates::class);
        $agreement = $gates->sellerAgreement();
        $vendors = [];

        foreach ($specs as $index => $spec) {
            $number = str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
            $owner = User::query()->firstOrCreate(['email' => "bigcat-{$code}-{$number}@nawy.test"], [
                'name' => $spec['name'], 'password' => $password, 'preferred_locale' => 'en',
                'home_country_id' => $this->country->id, 'email_verified_at' => $this->now, 'status' => 'active',
            ]);
            $rating = 3.8 + (crc32($spec['slug']) % 12) / 10;
            $vendor = Vendor::query()->where('slug', $spec['slug'])->first();

            if ($vendor !== null && ($vendor->legacy_id !== null || (int) $vendor->owner_user_id !== $owner->id)) {
                // A store with this name already exists and is not ours: use a distinct slug.
                $spec['slug'] .= '-'.$code;
                $vendor = Vendor::query()->where('slug', $spec['slug'])->first();
            }

            $attributes = [
                'owner_user_id' => $owner->id, 'type' => 'product', 'store_name' => $spec['name'],
                'contact_phone' => (self::DIAL[$code] ?? '+9715').str_pad((string) (1000000 + $index * 7919 % 8999999), 7, '0', STR_PAD_LEFT),
                'business_details' => ['name_ar' => $spec['name_ar'], 'seed' => 'big-catalog', 'family' => $spec['family']],
                'status' => Vendor::STATUS_ACTIVE, 'rating' => number_format(min(4.9, $rating), 2), 'rating_count' => 50 + crc32($spec['slug'].'r') % 9000,
                'verified_at' => $vendor?->verified_at ?? $this->now, 'seller_agreement_accepted_at' => $vendor?->seller_agreement_accepted_at ?? $this->now,
            ];
            $vendor === null ? $vendor = Vendor::query()->forceCreate(['slug' => $spec['slug']] + $attributes) : $vendor->forceFill($attributes)->save();

            $owner->memberships()->firstOrCreate(
                ['role' => MembershipRole::ProductVendor, 'country_id' => $this->country->id, 'tenant_type' => PanelCountries::VENDOR_TENANT, 'tenant_id' => $vendor->id],
                ['status' => 'active'],
            );

            if ($agreement !== null && ! $gates->sellerAgreementAccepted($vendor)) {
                app(ContractSigner::class)->sign($agreement, SignerType::Vendor, $vendor->id, $owner->id, $owner->name, SignatureMethod::Click, '127.0.0.1', 'BigCatalogSeeder');
            }

            $vendors[] = ['id' => $vendor->id, 'slug' => $vendor->slug, 'family' => $spec['family'], 'brand' => $spec['brand'], 'rating' => (float) $vendor->rating];
        }

        ($this->log)("  {$code}: ".count($vendors).' vendors ready');

        return $vendors;
    }

    private function warehouse(): int
    {
        $code = strtoupper($this->country->iso2).'-FC1';
        $id = $this->db->table('warehouses')->where('code', $code)->value('id');

        if ($id === null) {
            app(FbnDevSeeder::class)->run();
            $id = $this->db->table('warehouses')->where('code', $code)->value('id');
        }

        return (int) $id;
    }

    /**
     * @param  array<int, array{id: int, root: string, l2: string, slug: string, archetype: string, weight: float}>  $leaves
     * @param  array<string, array<int, array{id: int, slug: string, name: string, name_ar: string}>>  $brands
     * @param  array<int, array{id: int, slug: string, family: string, brand: string|null, rating: float}>  $vendors
     * @return array{products: int, variants: int, offers: int, media: int}
     */
    private function products(int $count, int $seed, array $leaves, array $brands, array $vendors, int $warehouseId): array
    {
        // Quota per leaf by weight (largest remainder so the total is exact).
        $weightSum = array_sum(array_column($leaves, 'weight'));
        $quota = array_map(fn (array $leaf): float => $count * $leaf['weight'] / $weightSum, $leaves);
        $plan = array_map(fn (float $q): int => (int) floor($q), $quota);
        $remainders = array_map(fn (float $q, int $f): float => $q - $f, $quota, $plan);
        arsort($remainders);

        foreach (array_slice(array_keys($remainders), 0, $count - array_sum($plan)) as $index) {
            $plan[$index]++;
        }

        $assignments = [];

        foreach ($leaves as $index => $leaf) {
            for ($k = 0; $k < $plan[$index]; $k++) {
                $assignments[] = $leaf;
            }
        }

        // Interleave so a chunk spans many categories and numbers stay stable per leaf order.
        $totals = ['products' => 0, 'variants' => 0, 'offers' => 0, 'media' => 0];
        $byFamily = [];

        foreach ($vendors as $vendor) {
            $byFamily[$vendor['family']][] = $vendor;
        }

        $officialByBrand = [];

        foreach ($vendors as $vendor) {
            if ($vendor['brand'] !== null) {
                $officialByBrand[$vendor['brand']] = $vendor;
            }
        }

        foreach (array_chunk($assignments, self::CHUNK, true) as $chunk) {
            $built = [];

            foreach ($chunk as $number => $leaf) {
                $built[] = $this->buildProduct($number + 1, $seed, $leaf, $brands, $byFamily, $vendors, $officialByBrand);
            }

            $chunkTotals = $this->writeChunk($built, $warehouseId);

            foreach ($chunkTotals as $key => $value) {
                $totals[$key] += $value;
            }

            ($this->log)("  {$this->country->code}: {$totals['products']}/{$count} products, {$totals['offers']} offers");
        }

        $this->rankBestsellers();

        return $totals;
    }

    /**
     * @param  array{id: int, root: string, l2: string, slug: string, archetype: string, weight: float}  $leaf
     * @param  array<string, array<int, array{id: int, slug: string, name: string, name_ar: string}>>  $brands
     * @param  array<string, array<int, array{id: int, slug: string, family: string, brand: string|null, rating: float}>>  $byFamily
     * @param  array<int, array{id: int, slug: string, family: string, brand: string|null, rating: float}>  $vendors
     * @param  array<string, array{id: int, slug: string, family: string, brand: string|null, rating: float}>  $officialByBrand
     * @return array<string, mixed>
     */
    private function buildProduct(int $number, int $seed, array $leaf, array $brands, array $byFamily, array $vendors, array $officialByBrand): array
    {
        $code = $this->country->code;
        $random = new Randomizer(new Mt19937(crc32("{$seed}|{$code}|{$number}")));
        $pick = fn (array $values) => $values[$random->getInt(0, count($values) - 1)];
        [$imageKey, $family, $minAed, $maxAed, $nouns, $axes, $genders] = BigCatalogBlueprint::archetypes()[$leaf['archetype']];

        $brand = $pick($brands[$family]);
        [$nounEn, $nounAr] = $pick($nouns);
        $model = $pick(BigCatalogBlueprint::MODELS).($random->getInt(0, 2) === 0 ? ' '.$random->getInt(2, 9) : '');
        $gender = $genders === [] ? null : $pick($genders);
        $titleEn = "{$brand['name']} {$model} {$nounEn}";
        $titleAr = "{$nounAr} {$brand['name_ar']} {$model}";
        $slug = Str::slug($titleEn).'-'.$number;
        $baseAed = $minAed + ($maxAed - $minAed) * $random->getFloat(0, 1) ** 1.6;

        // Variants: combinations of up to two axes, 1–4 variants.
        $axisValues = [];

        foreach ($axes as $axis) {
            $options = BigCatalogBlueprint::AXES[$axis];
            $take = $random->getInt(1, min(3, count($options)));
            $start = $random->getInt(0, count($options) - 1);
            $axisValues[$axis] = array_map(fn (int $k): array => [$k, $options[($start + $k) % count($options)]], range(0, $take - 1));
        }

        $combos = [[]];

        foreach ($axisValues as $axis => $values) {
            $next = [];

            foreach ($combos as $combo) {
                foreach ($values as [$rank, $value]) {
                    $next[] = $combo + [$axis => [$rank, $value]];
                }
            }

            $combos = $next;
        }

        $combos = array_slice($combos, 0, 4);
        $variants = [];

        foreach ($combos as $v => $combo) {
            $options = [];
            $multiplier = 1.0;

            foreach ($combo as $axis => [$rank, [$valueEn]]) {
                $options[$axis === 'shoe' ? 'size' : $axis] = $valueEn;
                $multiplier += $axis === 'storage' ? 0.18 * $rank : 0;
            }

            if ($gender !== null) {
                $options['gender'] = $gender;
            }

            $variants[] = ['sku' => self::MODEL_PREFIX.strtoupper($code).'-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT).'-'.($v + 1), 'options' => $options, 'aed' => $baseAed * $multiplier];
        }

        // Offers: 1–6 stores (most 2–6), the brand's official store when it has one.
        $offerCount = $pick([1, 2, 2, 3, 3, 3, 4, 4, 5, 6]);
        $pool = array_merge($byFamily[$family] ?? [], $byFamily['general'] ?? []);
        $pool = $pool === [] ? $vendors : $pool;
        $chosen = isset($officialByBrand[$brand['slug']]) ? [$officialByBrand[$brand['slug']]] : [];

        while (count($chosen) < min($offerCount, count($pool))) {
            $candidate = $pick($pool);

            if (! in_array($candidate['id'], array_column($chosen, 'id'), true)) {
                $chosen[] = $candidate;
            }
        }

        $offers = [];
        $refurbishable = $family === 'tech' && in_array($leaf['archetype'], ['phone', 'tablet', 'laptop', 'smartwatch', 'console', 'camera', 'earbuds', 'headphones'], true);

        foreach ($chosen as $o => $vendor) {
            $fbn = $random->getInt(1, 100) <= 28;
            $condition = $refurbishable && $o > 0 && $random->getInt(1, 100) <= 18 ? $pick(['refurbished', 'refurbished', 'used']) : 'new';
            $offers[] = [
                'vendor' => $vendor, 'factor' => 0.93 + $random->getFloat(0, 0.15), 'fbn' => $fbn,
                'handling' => $fbn ? $pick([0, 0, 1]) : $pick([0, 1, 1, 2, 2, 3]),
                'condition' => $condition, 'grade' => $condition === 'new' ? null : $pick(['A', 'A', 'B', 'C']),
                'sale' => $random->getInt(1, 100) <= 35 ? 0.6 + $random->getFloat(0, 0.3) : null,
                'drop' => $random->getInt(1, 100) <= 12 ? [1.08 + $random->getFloat(0, 0.25), $random->getInt(0, 25)] : null,
                'stock' => $random->getInt(1, 100) <= 3 ? 0 : $random->getInt(4, 140),
            ];
        }

        $units = (int) floor(2000 * $random->getFloat(0, 1) ** 6);
        $ratingCount = (int) floor(5200 * $random->getFloat(0, 1) ** 3);
        $daysOld = $random->getInt(1, 100) <= 10 ? $random->getInt(0, 28) : $random->getInt(31, 400);

        return [
            'number' => $number, 'leaf' => $leaf, 'image' => $imageKey, 'brand' => $brand, 'slug' => $slug,
            'title_en' => $titleEn, 'title_ar' => $titleAr, 'noun_en' => $nounEn, 'noun_ar' => $nounAr, 'model' => $model,
            'units' => $units, 'rating' => $ratingCount === 0 ? null : number_format(min(5.0, 3.4 + $random->getFloat(0, 1.6)), 2),
            'rating_count' => $ratingCount, 'created_at' => $this->now->subDays($daysOld)->setTime($random->getInt(9, 20), $random->getInt(0, 59)),
            'variants' => $variants, 'offers' => $offers,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $built
     * @return array{products: int, variants: int, offers: int, media: int}
     */
    private function writeChunk(array $built, int $warehouseId): array
    {
        $now = $this->now;
        $currency = $this->country->currency;

        // 1. Products (natural key: slug).
        $rows = array_map(fn (array $p): array => [
            'category_id' => $p['leaf']['id'], 'brand_id' => $p['brand']['id'], 'slug' => $p['slug'],
            'title_en' => $p['title_en'], 'title_ar' => $p['title_ar'],
            'model_number' => self::MODEL_PREFIX.str_pad((string) $p['number'], 5, '0', STR_PAD_LEFT),
            'description_en' => "The {$p['title_en']} from {$p['brand']['name']}: dependable quality, sold by trusted Nawy sellers in {$this->country->name_en} with fast delivery and easy returns.",
            'description_ar' => "{$p['title_ar']} من {$p['brand']['name_ar']}: جودة موثوقة من بائعين معتمدين على ناوي مع توصيل سريع وإرجاع سهل.",
            'highlights' => json_encode(["Genuine {$p['brand']['name']} product", "{$p['noun_en']} — model {$p['model']}", 'Easy 15-day returns'], JSON_UNESCAPED_UNICODE),
            'highlights_ar' => json_encode(["منتج أصلي من {$p['brand']['name_ar']}", "{$p['noun_ar']} — موديل {$p['model']}", 'إرجاع سهل خلال 15 يوماً'], JSON_UNESCAPED_UNICODE),
            'specifications' => json_encode(['Brand' => $p['brand']['name'], 'Model' => $p['model'], 'Warranty' => '1 year'], JSON_UNESCAPED_UNICODE),
            'specifications_ar' => json_encode(['العلامة التجارية' => $p['brand']['name_ar'], 'الموديل' => $p['model'], 'الضمان' => 'سنة واحدة'], JSON_UNESCAPED_UNICODE),
            'status' => 'active', 'units_sold_30d' => $p['units'], 'fulfilment_mode' => 'ready_to_ship',
            'rating_average' => $p['rating'], 'rating_count' => $p['rating_count'],
            'created_at' => $p['created_at'], 'updated_at' => $now,
        ], $built);
        $this->db->table('products')->upsert($rows, ['slug'], array_values(array_diff(array_keys($rows[0]), ['slug', 'created_at'])));
        $productIds = $this->db->table('products')->whereIn('slug', array_column($rows, 'slug'))->pluck('id', 'slug');

        // 2. Variants (natural key: sku).
        $variantRows = [];

        foreach ($built as $p) {
            foreach ($p['variants'] as $v) {
                $variantRows[] = ['product_id' => $productIds[$p['slug']], 'sku' => $v['sku'], 'barcode' => substr((string) crc32($v['sku']).'0000000000000', 0, 13),
                    'option_values' => json_encode($v['options'] ?: null, JSON_UNESCAPED_UNICODE), 'weight_grams' => 300 + crc32($v['sku']) % 4000,
                    'length_cm' => 20, 'width_cm' => 15, 'height_cm' => 10, 'created_at' => $now, 'updated_at' => $now];
            }
        }

        $this->db->table('product_variants')->upsert($variantRows, ['sku'], ['product_id', 'option_values', 'weight_grams', 'updated_at']);
        $variantIds = $this->db->table('product_variants')->whereIn('sku', array_column($variantRows, 'sku'))->pluck('id', 'sku');

        // 3. Media: product gallery (demo image + lifestyle shot) and one per variant; rebuilt each run (our rows only).
        $ids = $productIds->values()->all();
        $this->db->table('product_media')->whereIn('product_id', $ids)->where('path', 'like', BigCatalogBlueprint::IMAGE_DIR.'/%')->delete();
        $media = [];

        foreach ($built as $p) {
            $productId = $productIds[$p['slug']];
            [$w, $h] = $this->imageSizes[$p['image']];
            $media[] = ['product_id' => $productId, 'product_variant_id' => null, 'path' => $this->imagePath($p['image']), 'alt_en' => $p['title_en'], 'alt_ar' => $p['title_ar'], 'width' => $w, 'height' => $h, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now];
            [$fw, $fh] = $this->imageSizes['flatlay'];
            $media[] = ['product_id' => $productId, 'product_variant_id' => null, 'path' => $this->imagePath('flatlay'), 'alt_en' => "{$p['title_en']} — lifestyle", 'alt_ar' => "{$p['title_ar']} — صورة لايف ستايل", 'width' => $fw, 'height' => $fh, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now];

            foreach ($p['variants'] as $v) {
                $label = implode(' / ', array_values($v['options']));
                $media[] = ['product_id' => $productId, 'product_variant_id' => $variantIds[$v['sku']], 'path' => $this->imagePath($p['image']), 'alt_en' => trim("{$p['title_en']} {$label}"), 'alt_ar' => trim("{$p['title_ar']} {$label}"), 'width' => $w, 'height' => $h, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now];
            }
        }

        foreach (array_chunk($media, 1000) as $part) {
            $this->db->table('product_media')->insert($part);
        }

        // 4. Offers (natural key: vendor × variant × condition).
        $listingRows = [];
        $stock = [];

        foreach ($built as $p) {
            foreach ($p['variants'] as $v) {
                foreach ($p['offers'] as $offer) {
                    $aed = $v['aed'] * $offer['factor'] * ($offer['condition'] === 'new' ? 1 : 0.78);
                    $price = BigCatalogBlueprint::price($aed, $currency);
                    $sale = $offer['sale'] !== null ? BigCatalogBlueprint::price($aed * $offer['sale'], $currency) : null;
                    $previous = $offer['drop'] !== null && $sale === null ? BigCatalogBlueprint::price($aed * $offer['drop'][0], $currency) : null;
                    $key = $offer['vendor']['id'].'|'.$variantIds[$v['sku']].'|'.$offer['condition'];
                    $listingRows[] = [
                        'vendor_id' => $offer['vendor']['id'], 'product_variant_id' => $variantIds[$v['sku']],
                        'vendor_sku' => strtoupper(substr($offer['vendor']['slug'], 0, 6)).'-'.$v['sku'],
                        'status' => 'active', 'price' => $price, 'sale_price' => $sale !== null && bccomp($sale, $price, 4) < 0 ? $sale : null,
                        'previous_price' => $previous, 'price_dropped_at' => $previous === null ? null : $now->subDays($offer['drop'][1])->setTime(12, 0),
                        'fulfilment' => $offer['fbn'] ? 'fbn' : 'fbm', 'condition' => $offer['condition'], 'grade' => $offer['grade'],
                        'handling_days' => $offer['handling'], 'created_at' => $now, 'updated_at' => $now,
                    ];
                    $stock[$key] = [$offer['stock'], $offer['fbn']];
                }
            }
        }

        $this->db->table('listings')->upsert($listingRows, ['vendor_id', 'product_variant_id', 'condition'],
            ['vendor_sku', 'status', 'price', 'sale_price', 'previous_price', 'price_dropped_at', 'fulfilment', 'grade', 'handling_days', 'updated_at']);
        $listings = $this->db->table('listings')->whereIn('product_variant_id', $variantIds->values())->get(['id', 'vendor_id', 'product_variant_id', 'condition']);

        // 5. Stock, once per offer (a rerun never adds stock twice): ledger movement, and the warehouse for FBN.
        $stocked = $this->db->table('inventory_movements')->whereIn('listing_id', $listings->pluck('id'))->distinct()->pluck('listing_id')->flip();
        $movements = [];
        $warehouse = [];
        $warehouseMovements = [];

        foreach ($listings as $listing) {
            [$quantity, $fbn] = $stock["{$listing->vendor_id}|{$listing->product_variant_id}|{$listing->condition}"] ?? [0, false];

            if ($quantity <= 0 || isset($stocked[$listing->id])) {
                continue;
            }

            $movements[] = ['listing_id' => $listing->id, 'quantity' => $quantity, 'reason' => $fbn ? 'inbound_received' : 'restock', 'reference_type' => 'dev_seed', 'reference_id' => null, 'actor_user_id' => null, 'created_at' => $now];

            if ($fbn) {
                $warehouse[] = ['warehouse_id' => $warehouseId, 'listing_id' => $listing->id, 'on_hand' => $quantity, 'reserved' => 0, 'damaged' => 0, 'bin_code' => 'B-'.str_pad((string) ($listing->id % 999), 3, '0', STR_PAD_LEFT), 'first_received_at' => $now, 'created_at' => $now, 'updated_at' => $now];
                $warehouseMovements[] = ['warehouse_id' => $warehouseId, 'listing_id' => $listing->id, 'bucket' => 'on_hand', 'quantity' => $quantity, 'reason' => 'inbound_received', 'reference_type' => 'dev_seed', 'reference_id' => null, 'actor_user_id' => null, 'note' => 'Big catalogue', 'created_at' => $now];
            }
        }

        foreach (array_chunk($movements, 1000) as $part) {
            $this->db->table('inventory_movements')->insert($part);
        }

        foreach (array_chunk($warehouse, 1000) as $part) {
            $this->db->table('warehouse_stock')->insertOrIgnore($part);
        }

        foreach (array_chunk($warehouseMovements, 1000) as $part) {
            $this->db->table('warehouse_stock_movements')->insert($part);
        }

        return ['products' => count($rows), 'variants' => count($variantRows), 'offers' => count($listingRows), 'media' => count($media)];
    }

    /** Weekly bestseller rank among the big-catalogue products (units sold, then rating). */
    private function rankBestsellers(): void
    {
        $ids = $this->db->table('products')->where('model_number', 'like', self::MODEL_PREFIX.'%')
            ->orderByDesc('units_sold_30d')->orderByDesc('rating_count')->orderBy('id')->pluck('id');
        $this->db->table('products')->whereIn('id', $ids)->update(['bestseller_rank' => null]);

        foreach ($ids->take(300)->values() as $rank => $id) {
            $this->db->table('products')->where('id', $id)->update(['bestseller_rank' => $rank + 1]);
        }
    }

    /** "Mega deals": a live flash sale (ends 7 days from the run) with ~36 deals across categories. */
    private function flashSale(): void
    {
        $values = ['name' => 'Mega deals', 'display_name' => 'Mega deals', 'status' => 'live', 'submission_starts_at' => $this->now->subDays(3),
            'submission_ends_at' => $this->now->subDay(), 'starts_at' => $this->now->subHours(6), 'ends_at' => $this->now->addDays(7)->startOfHour(),
            'max_qty_per_customer' => 2, 'updated_at' => $this->now];
        $this->db->table('flash_sale_events')->updateOrInsert(['slug' => self::FLASH_SALE_SLUG], $values);
        $eventId = (int) $this->db->table('flash_sale_events')->where('slug', self::FLASH_SALE_SLUG)->value('id');
        $this->db->table('flash_sale_events')->where('id', $eventId)->whereNull('created_at')->update(['created_at' => $this->now]);

        // Two deals per root: the best-rated in-stock new offer of well-rated products.
        $picked = [];

        foreach (array_keys(BigCatalogBlueprint::tree()) as $root) {
            $rows = $this->db->table('listings as l')
                ->join('product_variants as v', 'v.id', '=', 'l.product_variant_id')
                ->join('products as p', 'p.id', '=', 'v.product_id')
                ->join('categories as c3', 'c3.id', '=', 'p.category_id')
                ->join('categories as c2', 'c2.id', '=', 'c3.parent_id')
                ->join('categories as c1', 'c1.id', '=', 'c2.parent_id')
                ->where('c1.slug', $root)->where('p.model_number', 'like', self::MODEL_PREFIX.'%')
                ->where('l.status', 'active')->where('l.condition', 'new')->whereNull('l.sale_price')
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('inventory_movements as m')->whereColumn('m.listing_id', 'l.id'))
                ->orderByDesc('p.rating_count')->limit(12)->get(['l.id', 'l.vendor_id', 'l.price', 'p.id as product_id']);

            foreach ($rows->unique('product_id')->take(2) as $row) {
                $picked[] = $row;
            }
        }

        $this->db->table('flash_sale_submissions')->where('flash_sale_event_id', $eventId)->delete();
        $decimals = BigCatalogBlueprint::RATES[$this->country->currency][1] ?? 2;
        $this->db->table('flash_sale_submissions')->insert(array_map(fn ($row, int $i): array => [
            'flash_sale_event_id' => $eventId, 'listing_id' => $row->id, 'vendor_id' => $row->vendor_id, 'status' => 'live',
            'original_price' => $row->price, 'flash_price' => number_format((float) $row->price * (0.55 + ($i % 5) * 0.05), $decimals, '.', ''),
            'quantity_cap' => 40 + $i * 3, 'sold_quantity' => ($i * 7) % 30, 'created_at' => $this->now, 'updated_at' => $this->now,
        ], $picked, array_keys($picked)));
    }

    /** The coupons the design shows (platform-funded, public). */
    private function coupons(): void
    {
        $currency = $this->country->currency;
        $categoryIds = fn (array $slugs): string => json_encode($this->db->table('categories')->whereIn('slug', $slugs)->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $coupons = [
            'FIRST15' => ['Welcome to Nawy: 15% cashback on your first order', 'percentage', '15', 'all', null, ['first_order_only' => true, 'max_discount_amount' => BigCatalogBlueprint::price(150, $currency)]],
            'NAWY10' => ['Extra 10% off with Nawy bank cards', 'percentage', '10', 'all', null, ['max_discount_amount' => BigCatalogBlueprint::price(100, $currency), 'min_order_amount' => BigCatalogBlueprint::price(200, $currency)]],
            'SCENT10' => ['10% off fragrances', 'percentage', '10', 'category', $categoryIds(['fragrances', 'womens-fragrances', 'mens-fragrances', 'fragrances-oud']), []],
            'GLOW' => ['Free beauty box: 15% off beauty', 'percentage', '15', 'category', $categoryIds(['beauty-fragrances', 'beauty', 'makeup', 'skincare']), []],
            'SALE200' => ['Extra 200 off on smartphones', 'fixed_amount', BigCatalogBlueprint::price(200, $currency), 'category', $categoryIds(['mobiles', 'mobiles-smartphones', 'mobiles-foldables']), ['min_order_amount' => BigCatalogBlueprint::price(1500, $currency)]],
            'WELL' => ['Free wellness box: 10% off health', 'percentage', '10', 'category', $categoryIds(['health', 'vitamins', 'medical-devices', 'sports', 'fitness']), []],
            'BUY2' => ['Buy 2, get 10% off on fragrances', 'percentage', '10', 'category', $categoryIds(['fragrances', 'womens-fragrances', 'mens-fragrances', 'fragrances-oud']), ['buy_quantity' => 2]],
            'FREEBUDS' => ['Free earbuds worth 249 with selected smartphones', 'fixed_amount', BigCatalogBlueprint::price(249, $currency), 'category', $categoryIds(['mobiles', 'mobiles-smartphones', 'mobiles-foldables']), ['min_order_amount' => BigCatalogBlueprint::price(999, $currency)]],
        ];

        foreach ($coupons as $code => [$description, $type, $value, $target, $targetIds, $extra]) {
            $this->db->table('coupons')->updateOrInsert(['code' => $code], [
                'description' => $description, 'scope' => 'platform', 'vendor_id' => null, 'funded_by' => 'platform',
                'target_type' => $target, 'target_ids' => $targetIds, 'first_order_only' => $extra['first_order_only'] ?? false,
                'customer_segment' => 'everyone', 'type' => $type, 'value' => $value, 'buy_quantity' => $extra['buy_quantity'] ?? null, 'get_quantity' => null,
                'min_order_amount' => $extra['min_order_amount'] ?? null, 'max_discount_amount' => $extra['max_discount_amount'] ?? null,
                'usage_limit' => null, 'per_customer_limit' => 3, 'starts_at' => $this->now->subDay(), 'ends_at' => $this->now->addDays(60),
                'is_active' => true, 'is_public' => true, 'admin_disabled_at' => null, 'updated_at' => $this->now,
            ]);
            $this->db->table('coupons')->where('code', $code)->whereNull('created_at')->update(['created_at' => $this->now]);
        }
    }

    /**
     * Sponsored placements per root category: a brand banner booking (the
     * "Explore more" / every-4-rows banner) and sponsored-product campaigns.
     *
     * @param  array<int, array{id: int, slug: string, family: string, brand: string|null, rating: float}>  $vendors
     */
    private function ads(array $vendors): void
    {
        $official = collect($vendors)->whereNotNull('brand')->keyBy('brand');
        $code = $this->country->code;
        $brandFor = ['electronics' => 'soundmax', 'beauty-fragrances' => 'al-noor', 'beauty' => 'verda', 'womens-fashion' => 'stride', 'mens-fashion' => 'stride',
            'fashion' => 'stride', 'home-kitchen' => 'brewmaster', 'home' => 'casa-bella', 'sports' => 'stride'];
        $headlines = ['soundmax' => 'Hear every detail', 'al-noor' => 'Scents of the Gulf', 'verda' => 'Glow, naturally', 'stride' => 'Move in comfort', 'brewmaster' => 'Café at home', 'casa-bella' => 'Live beautifully'];
        $sequence = 0;

        foreach (BigCatalogBlueprint::tree() as $root => $definition) {
            $rootId = $this->db->table('categories')->where('slug', $root)->value('id');
            $brand = $brandFor[$root] ?? 'nova';
            $vendor = $official[$brand] ?? $official['nova'];

            $slotCode = "bc-{$root}-brand";
            $this->db->table('ad_slots')->updateOrInsert(['code' => $slotCode], [
                'name' => "{$definition[0]} sponsored brand banner", 'placement' => 'sponsored_brand_banner', 'category_id' => $rootId,
                'dimensions' => '1200x300', 'price_per_day' => BigCatalogBlueprint::price(150, $this->country->currency), 'max_concurrent' => 2, 'is_active' => true, 'updated_at' => $this->now,
            ]);
            $slotId = $this->db->table('ad_slots')->where('code', $slotCode)->value('id');
            $this->db->table('ad_bookings')->updateOrInsert(['number' => strtoupper("BC-{$code}-B".str_pad((string) ++$sequence, 3, '0', STR_PAD_LEFT))], [
                'ad_slot_id' => $slotId, 'advertiser_type' => 'vendor', 'advertiser_id' => $vendor['id'],
                'starts_on' => $this->now->subDays(2)->toDateString(), 'ends_on' => $this->now->addDays(60)->toDateString(), 'days' => 63,
                'currency' => $this->country->currency, 'amount' => BigCatalogBlueprint::price(150 * 63, $this->country->currency),
                'creative_path' => $this->imagePath('banner'), 'creative_type' => 'image', 'link_url' => "/brands/{$brand}",
                'headline' => $headlines[$brand] ?? 'Shop the brand', 'status' => 'active', 'payment_status' => 'paid', 'updated_at' => $this->now,
            ]);

            // Sponsored products: 4 campaigns on offers of this root (descendants).
            $categoryIds = $this->descendants((int) $rootId);
            $listingIds = $this->db->table('listings as l')->join('product_variants as v', 'v.id', '=', 'l.product_variant_id')->join('products as p', 'p.id', '=', 'v.product_id')
                ->whereIn('p.category_id', $categoryIds)->where('p.model_number', 'like', self::MODEL_PREFIX.'%')->where('l.status', 'active')
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('inventory_movements as m')->whereColumn('m.listing_id', 'l.id'))
                ->orderByDesc('p.rating_count')->limit(40)->get(['l.id', 'l.vendor_id', 'p.id as product_id'])->unique('product_id')->take(4)->values();

            foreach ($listingIds as $i => $row) {
                $this->db->table('ad_campaigns')->updateOrInsert(['number' => strtoupper("BC-{$code}-C".str_pad((string) $sequence, 3, '0', STR_PAD_LEFT).$i)], [
                    'advertiser_type' => 'vendor', 'advertiser_id' => $row->vendor_id, 'listing_id' => $row->id, 'name' => "{$definition[0]} sponsored ".($i + 1),
                    'pricing_model' => 'cpc', 'bid' => BigCatalogBlueprint::price(2, $this->country->currency), 'currency' => $this->country->currency,
                    'daily_budget' => BigCatalogBlueprint::price(500, $this->country->currency), 'total_budget' => null,
                    'keywords' => json_encode([$root, $definition[0]]), 'category_ids' => json_encode($categoryIds),
                    'starts_on' => $this->now->subDays(2)->toDateString(), 'ends_on' => $this->now->addDays(60)->toDateString(),
                    'status' => 'active', 'total_exhausted' => false, 'updated_at' => $this->now,
                ]);
            }
        }

        $this->db->table('ad_slots')->where('code', 'like', 'bc-%')->whereNull('created_at')->update(['created_at' => $this->now]);
        $this->db->table('ad_bookings')->where('number', 'like', 'BC-%')->whereNull('created_at')->update(['created_at' => $this->now]);
        $this->db->table('ad_campaigns')->where('number', 'like', 'BC-%')->whereNull('created_at')->update(['created_at' => $this->now]);
    }

    /**
     * @return array<int, int>
     */
    private function descendants(int $rootId): array
    {
        $ids = [$rootId];

        for ($frontier = [$rootId], $depth = 0; $frontier !== [] && $depth < 5; $depth++) {
            $frontier = $this->db->table('categories')->whereIn('parent_id', $frontier)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }

    /** Category tree, brand list, mega menu and block caches of this country. */
    private function forgetCaches(): void
    {
        foreach (['en', 'ar'] as $locale) {
            Cache::forget(CatalogController::treeCacheKey($this->country->code, $locale));
        }

        foreach ([PublicCache::BRANDS, PublicCache::CATALOG_MENU, BlockProducts::CACHE_GROUP] as $group) {
            PublicCache::flushCountry($group);
        }
    }
}
