<?php

use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Support\CategoryMenu;
use App\Domains\Listings\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
    Cache::flush();
});

/**
 * A sellable product in `$category` (optionally of `$brand`).
 */
function menuProduct(Category $category, ?Brand $brand = null): Listing
{
    $listing = sellableListing();
    $listing->variant->product->update(['category_id' => $category->id, 'brand_id' => $brand?->id]);

    return $listing;
}

/**
 * @return array<string, mixed>|null
 */
function menuRoot(string $market, string $slug): ?array
{
    return collect(test()->getJson("/api/v1/{$market}/catalog/menu")->assertOk()->json('data'))->firstWhere('slug', $slug);
}

/**
 * Electronics → Mobiles (Smartphones with products, Feature phones empty), an empty and an inactive level 2.
 *
 * @return array<string, Category>
 */
function menuTree(): array
{
    $root = Category::factory()->create(['slug' => 'electronics', 'name_en' => 'Electronics', 'name_ar' => 'إلكترونيات', 'image_path' => 'products/demo/product-smartphone.png', 'sort_order' => 1]);
    $mobiles = Category::factory()->childOf($root)->create(['slug' => 'mobiles', 'name_en' => 'Mobiles', 'name_ar' => 'جوالات']);
    $smartphones = Category::factory()->childOf($mobiles)->create(['slug' => 'smartphones', 'name_en' => 'Smartphones', 'name_ar' => 'هواتف ذكية']);
    $featurePhones = Category::factory()->childOf($mobiles)->create(['slug' => 'feature-phones']);
    $cameras = Category::factory()->childOf($root)->create(['slug' => 'cameras']);
    $hidden = Category::factory()->childOf($root)->create(['slug' => 'hidden-l2', 'is_active' => false]);
    menuProduct(Category::factory()->childOf($hidden)->create(['slug' => 'hidden-l3']));

    return compact('root', 'mobiles', 'smartphones', 'featurePhones', 'cameras');
}

it('serves top-level → level-2 columns → level-3 links with paths, image and product counts, hiding inactive and empty sub-categories', function () {
    $tree = menuTree();
    menuProduct($tree['smartphones']);
    menuProduct($tree['smartphones']);

    $root = menuRoot('uae-en', 'electronics');

    expect($root)->toMatchArray(['name' => 'Electronics', 'path' => '/electronics', 'product_count' => 2])
        ->and($root['image'])->toStartWith('http')->toEndWith('/storage/products/demo/product-smartphone.png')
        ->and($root['children'])->toHaveCount(1)
        ->and($root['children'][0])->toMatchArray(['slug' => 'mobiles', 'name' => 'Mobiles', 'path' => '/electronics/mobiles', 'product_count' => 2])
        ->and($root['children'][0]['children'])->toBe([
            ['id' => $tree['smartphones']->id, 'slug' => 'smartphones', 'name' => 'Smartphones', 'path' => '/electronics/smartphones', 'product_count' => 2],
        ]);
});

it('localises category and brand names in Arabic', function () {
    $tree = menuTree();
    $brand = Brand::factory()->create(['slug' => 'soundmax', 'name' => 'SoundMax', 'name_ar' => 'ساوند ماكس']);
    menuProduct($tree['smartphones'], $brand);

    $root = menuRoot('uae-ar', 'electronics');

    expect($root['name'])->toBe('إلكترونيات')
        ->and($root['children'][0]['name'])->toBe('جوالات')
        ->and($root['children'][0]['children'][0]['name'])->toBe('هواتف ذكية')
        ->and($root['brands'][0])->toMatchArray(['slug' => 'soundmax', 'name' => 'ساوند ماكس', 'path' => '/electronics?brand=soundmax']);
});

it('lists only active brands with sellable products in the category (descendants included), most products first, at most eight', function () {
    $tree = menuTree();
    $big = Brand::factory()->create(['slug' => 'big', 'name' => 'Big', 'logo_path' => 'brands/big.png']);
    $small = Brand::factory()->create(['slug' => 'small', 'name' => 'Small']);
    $inactive = Brand::factory()->create(['slug' => 'off', 'is_active' => false]);
    $elsewhere = Brand::factory()->create(['slug' => 'elsewhere']);
    Brand::factory()->create(['slug' => 'no-products']);
    menuProduct($tree['smartphones'], $big);
    menuProduct($tree['mobiles'], $big);
    menuProduct($tree['smartphones'], $small);
    menuProduct($tree['smartphones'], $inactive);
    menuProduct(Category::factory()->create(['slug' => 'toys']), $elsewhere);
    $unsellable = menuProduct($tree['smartphones'], Brand::factory()->create(['slug' => 'unsellable']));
    $unsellable->variant->product->update(['status' => 'draft']);

    $brands = menuRoot('uae-en', 'electronics')['brands'];

    expect(array_column($brands, 'slug'))->toBe(['big', 'small'])
        ->and($brands[0])->toMatchArray(['product_count' => 2, 'path' => '/electronics?brand=big'])
        ->and($brands[0]['logo'])->toEndWith('/storage/brands/big.png')
        ->and($brands[1]['logo'])->toBeNull()
        ->and(array_column(menuRoot('uae-en', 'toys')['brands'], 'slug'))->toBe(['elsewhere']);

    foreach (range(1, 9) as $i) {
        menuProduct($tree['smartphones'], Brand::factory()->create(['slug' => "extra-{$i}"]));
    }
    Cache::flush();

    expect(menuRoot('uae-en', 'electronics')['brands'])->toHaveCount(CategoryMenu::TOP_BRANDS)
        ->and(menuRoot('uae-en', 'electronics')['brands'][0]['slug'])->toBe('big');
});

it('is cached per country and locale and forgotten when a category or brand changes', function () {
    $tree = menuTree();
    $brand = Brand::factory()->create(['slug' => 'soundmax', 'name' => 'SoundMax']);
    menuProduct($tree['smartphones'], $brand);
    $later = menuProduct($tree['smartphones'], $brand)->variant->product;
    $later->update(['status' => 'draft']);

    expect(menuRoot('uae-en', 'electronics')['product_count'])->toBe(1);

    // A product going live alone shows within the TTL (no menu flush on products / listings).
    $later->update(['status' => 'active']);
    expect(menuRoot('uae-en', 'electronics')['product_count'])->toBe(1);

    $tree['smartphones']->update(['name_en' => 'Smart phones']);
    $root = menuRoot('uae-en', 'electronics');
    expect($root['product_count'])->toBe(2)
        ->and($root['children'][0]['children'][0]['name'])->toBe('Smart phones');

    $brand->update(['name' => 'SoundMax Pro']);
    expect(menuRoot('uae-en', 'electronics')['brands'][0]['name'])->toBe('SoundMax Pro');

    $tree['mobiles']->update(['is_active' => false]);
    expect(menuRoot('uae-en', 'electronics')['children'])->toBe([]);

    // Oman has its own categories: the UAE menu never leaks into it.
    expect(menuRoot('omn-en', 'electronics'))->toBeNull();
});

it('lets an admin replace or remove a category image without deleting shared seeded images', function () {
    Storage::fake('public');
    Storage::disk('public')->put('products/demo/product-sneakers.png', 'png');
    $category = Category::factory()->create(['slug' => 'sneakers', 'image_path' => 'products/demo/product-sneakers.png']);
    $admin = adminAccount();
    $payload = ['name_en' => 'Sneakers', 'name_ar' => 'أحذية', 'slug' => 'sneakers', 'is_active' => 1, 'requires_contract' => 0, 'requires_certification' => 0];

    $this->actingAs($admin)->get(onPanel('admin', "catalog/categories/{$category->id}/edit"))->assertOk()->assertSee('product-sneakers.png');
    $this->actingAs($admin)->put(onPanel('admin', "catalog/categories/{$category->id}"), $payload + ['image' => UploadedFile::fake()->image('new.jpg')])->assertSessionHasNoErrors();
    $uploaded = $category->refresh()->image_path;
    expect($uploaded)->toStartWith('categories/uae/');
    Storage::disk('public')->assertExists(['products/demo/product-sneakers.png', $uploaded]);

    $this->actingAs($admin)->put(onPanel('admin', "catalog/categories/{$category->id}"), $payload + ['remove_image' => 1])->assertSessionHasNoErrors();
    expect($category->refresh()->image_path)->toBeNull();
    Storage::disk('public')->assertMissing($uploaded);
});
