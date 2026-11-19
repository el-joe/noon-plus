<?php

use App\Domains\Catalog\Models\Product;
use App\Domains\Content\Models\Page;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Listings\Models\Listing;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
| nawy:dev:big-catalog — the development big catalogue: deterministic,
| idempotent (a rerun updates, never duplicates), images stored once,
| multi-seller offers with stock, the flash sale / coupons / ads the design
| uses, published home + category pages, and refused in production.
*/

beforeEach(function () {
    $this->setUpCountries();
    $this->country = $this->actAsCountry('uae');
    Storage::fake('public');
    Cache::flush();
    $admin = User::factory()->create();
    $admin->memberships()->create(['role' => MembershipRole::Admin, 'country_id' => null, 'status' => 'active']);
});

/**
 * @return array<string, int>
 */
function bigCatalogCounts(): array
{
    $db = DB::connection(Product::query()->getModel()->getConnectionName());

    return [
        'products' => Product::query()->where('model_number', 'like', 'BC-%')->count(),
        'variants' => $db->table('product_variants')->where('sku', 'like', 'BC-%')->count(),
        'listings' => Listing::query()->count(),
        'movements' => $db->table('inventory_movements')->count(),
        'media' => $db->table('product_media')->where('path', 'like', 'products/demo/%')->count(),
        'vendors' => Vendor::query()->count(),
        'coupons' => $db->table('coupons')->count(),
        'submissions' => $db->table('flash_sale_submissions')->count(),
        'campaigns' => $db->table('ad_campaigns')->count(),
    ];
}

it('seeds a small catalogue and a rerun updates instead of duplicating', function () {
    $this->artisan('nawy:dev:big-catalog', ['--country' => 'uae', '--products' => 60, '--vendors' => 12, '--no-post' => true])->assertSuccessful();
    $first = bigCatalogCounts();

    expect($first['products'])->toBe(60)
        ->and($first['vendors'])->toBe(12)
        ->and($first['media'])->toBeGreaterThanOrEqual(60 * 3)
        ->and($first['coupons'])->toBe(8)
        ->and($first['submissions'])->toBeGreaterThan(0)
        ->and($first['campaigns'])->toBeGreaterThan(0);

    // Several sellers per product, stock on (almost) every offer, FBN in the warehouse.
    $sellers = DB::connection(Listing::query()->getModel()->getConnectionName())->table('listings as l')
        ->join('product_variants as v', 'v.id', '=', 'l.product_variant_id')
        ->selectRaw('v.product_id, count(distinct l.vendor_id) as sellers')->groupBy('v.product_id')->pluck('sellers');
    expect($sellers->filter(fn ($n): bool => (int) $n >= 2)->count())->toBeGreaterThan(30)
        ->and($first['movements'])->toBeGreaterThan((int) ($first['listings'] * 0.9));

    // Images are stored once, not per product.
    expect(Storage::disk('public')->allFiles('products/demo'))->toHaveCount(10);

    // Every product has a primary image that resolves to the demo folder.
    $product = Product::query()->where('model_number', 'BC-00001')->firstOrFail();
    expect($product->primaryImage()?->path)->toStartWith('products/demo/');

    // Pages: home + category pages, published, both languages.
    expect(Page::query()->where('slug', 'home')->where('status', 'published')->count())->toBe(2)
        ->and(Page::query()->where('template', 'category')->where('slug', 'electronics')->where('status', 'published')->count())->toBe(2);

    $this->artisan('nawy:dev:big-catalog', ['--country' => 'uae', '--products' => 60, '--vendors' => 12, '--no-post' => true])->assertSuccessful();

    expect(bigCatalogCounts())->toBe($first)
        ->and(Product::query()->where('model_number', 'BC-00001')->value('title_en'))->toBe($product->title_en);
});

it('refuses to run in production', function () {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('nawy:dev:big-catalog', ['--country' => 'uae', '--products' => 5, '--no-post' => true])->assertFailed();

    expect(Product::query()->count())->toBe(0);
});
