<?php

use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductMedia;
use App\Domains\Catalog\Models\ProductVariant;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Vendors\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| Storefront catalogue gaps (WP9): G-W1 images, G-W2 product page content,
| G-W4 facets / brands / category tree / category by slug, G-W10 per_page.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->actAsCountry('uae');
});

/**
 * A sellable product: one variant with the given options and an active
 * listing with stock from `$vendor` (new seller unless given).
 *
 * @param  array<string, mixed>  $productAttributes
 * @param  array<string, string>  $options
 */
function storefrontProduct(string $price, array $productAttributes = [], array $options = ['colour' => 'Black'], ?Vendor $vendor = null, int $stock = 5): Product
{
    $product = Product::factory()->create($productAttributes);
    $variant = ProductVariant::factory()->for($product)->create(['option_values' => $options]);
    $listing = Listing::factory()->for($vendor ?? Vendor::factory()->create())->for($variant, 'variant')->active()->create(['price' => $price]);

    if ($stock > 0) {
        app(InventoryLedger::class)->move($listing, $stock, 'restock');
    }

    return $product;
}

it('shows ordered product images with EN/AR alt text on cards and the product page, variant images first on the card', function () {
    $product = storefrontProduct('120.00', ['slug' => 'nova-phone']);
    $variant = $product->variants()->first();
    ProductMedia::query()->create(['product_id' => $product->id, 'path' => 'products/1/back.jpg', 'alt_en' => 'Back', 'alt_ar' => 'الخلف', 'sort_order' => 2]);
    ProductMedia::query()->create(['product_id' => $product->id, 'path' => 'products/1/front.jpg', 'alt_en' => 'Front', 'alt_ar' => 'الأمام', 'sort_order' => 1]);

    $this->getJson('/api/v1/uae-en/products/nova-phone')->assertOk()
        ->assertJsonCount(2, 'data.images')
        ->assertJsonPath('data.images.0.alt', 'Front')
        ->assertJsonPath('data.images.0.alt_ar', 'الأمام')
        ->assertJsonPath('data.images.1.alt', 'Back')
        ->assertJsonPath('data.images.0.variant_sku', null);
    $this->getJson('/api/v1/uae-ar/products/nova-phone')->assertJsonPath('data.images.0.alt', 'الأمام');
    $this->getJson('/api/v1/uae-en/products')->assertJsonPath('data.0.image.alt', 'Front');

    ProductMedia::query()->create(['product_id' => $product->id, 'product_variant_id' => $variant->id, 'path' => 'products/1/black.jpg', 'alt_en' => 'Black', 'sort_order' => 3]);
    expect($product->fresh()->primaryImage($variant->id)->alt_en)->toBe('Black')
        ->and($product->fresh()->primaryImage()->alt_en)->toBe('Front');
    $this->getJson('/api/v1/uae-en/products/nova-phone')->assertJsonPath('data.images.2.variant_sku', $variant->sku);
});

it('returns the product description, highlights and specifications in the request language with an EN/AR block', function () {
    storefrontProduct('99.00', [
        'slug' => 'oud-perfume',
        'description_en' => 'A warm oud.',
        'description_ar' => 'عود دافئ.',
        'highlights' => ['Long lasting', ['text' => '100 ml']],
        'highlights_ar' => ['يدوم طويلاً'],
        'specifications' => ['Volume' => '100 ml'],
        'specifications_ar' => [['label' => 'الحجم', 'value' => '100 مل']],
    ]);
    storefrontProduct('50.00', ['slug' => 'plain-soap', 'description_en' => 'Plain soap.', 'highlights' => ['Mild']]);

    $this->getJson('/api/v1/uae-en/products/oud-perfume')->assertOk()
        ->assertJsonPath('data.description', 'A warm oud.')
        ->assertJsonPath('data.highlights', ['Long lasting', '100 ml'])
        ->assertJsonPath('data.specifications', [['label' => 'Volume', 'value' => '100 ml']])
        ->assertJsonPath('data.content.ar.description', 'عود دافئ.')
        ->assertJsonPath('data.content.ar.specifications.0.label', 'الحجم');
    $this->getJson('/api/v1/uae-ar/products/oud-perfume')
        ->assertJsonPath('data.description', 'عود دافئ.')
        ->assertJsonPath('data.highlights', ['يدوم طويلاً']);

    // Arabic not written yet → English.
    $this->getJson('/api/v1/uae-ar/products/plain-soap')
        ->assertJsonPath('data.description', 'Plain soap.')
        ->assertJsonPath('data.highlights', ['Mild']);
});

it('filters products by several brands, seller, rating and variant attributes and echoes per_page', function () {
    $nova = Brand::factory()->create(['slug' => 'nova', 'name' => 'Nova']);
    $pulse = Brand::factory()->create(['slug' => 'pulse', 'name' => 'Pulse']);
    $store = Vendor::factory()->create(['slug' => 'gadget-hub']);
    storefrontProduct('100.00', ['slug' => 'nova-red', 'brand_id' => $nova->id, 'rating_average' => '4.60', 'rating_count' => 3], ['colour' => 'Red'], $store);
    storefrontProduct('200.00', ['slug' => 'pulse-blue', 'brand_id' => $pulse->id, 'rating_average' => '3.20', 'rating_count' => 1], ['colour' => 'Blue']);
    storefrontProduct('300.00', ['slug' => 'other-red', 'rating_average' => null], ['colour' => 'Red']);

    $slugs = fn (string $query): array => collect($this->getJson('/api/v1/uae-en/products?'.$query)->assertOk()->json('data'))->pluck('slug')->sort()->values()->all();

    expect($slugs('brand=nova,pulse'))->toBe(['nova-red', 'pulse-blue'])
        ->and($slugs('seller=gadget-hub'))->toBe(['nova-red'])
        ->and($slugs('rating=4'))->toBe(['nova-red'])
        ->and($slugs('attr[colour]=Red'))->toBe(['nova-red', 'other-red'])
        ->and($slugs('attr[colour]=Red&brand=nova'))->toBe(['nova-red']);

    $this->getJson('/api/v1/uae-en/products?per_page=2&sort=rating')
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.0.slug', 'nova-red')
        ->assertJsonPath('data.0.rating', ['average' => '4.60', 'count' => 3]);
    $this->getJson('/api/v1/uae-en/products?rating=9')->assertUnprocessable()->assertJsonPath('error.fields.rating.0', fn ($message) => is_string($message));
});

it('counts facets with every other filter applied, never the facet own filter', function () {
    $nova = Brand::factory()->create(['slug' => 'nova', 'name' => 'Nova']);
    $pulse = Brand::factory()->create(['slug' => 'pulse', 'name' => 'Pulse']);
    $store = Vendor::factory()->create(['slug' => 'gadget-hub', 'store_name' => 'Gadget Hub']);
    storefrontProduct('100.00', ['brand_id' => $nova->id, 'rating_average' => '4.50'], ['colour' => 'Red', 'size' => 'M'], $store);
    storefrontProduct('150.00', ['brand_id' => $nova->id, 'rating_average' => '3.50'], ['colour' => 'Blue'], $store);
    storefrontProduct('400.00', ['brand_id' => $pulse->id], ['colour' => 'Red']);
    storefrontProduct('999.00', ['brand_id' => $pulse->id], ['colour' => 'Red'], stock: 0);

    $facets = $this->getJson('/api/v1/uae-en/products/facets?brand=nova')->assertOk()->assertJsonPath('meta.total', 2)->json('data');

    expect($facets['brands'])->toBe([
        ['slug' => 'nova', 'name' => 'Nova', 'count' => 2],
        ['slug' => 'pulse', 'name' => 'Pulse', 'count' => 1],
    ])
        ->and($facets['sellers'])->toHaveCount(1)
        ->and($facets['sellers'][0])->toMatchArray(['slug' => 'gadget-hub', 'count' => 2])
        ->and($facets['price'])->toBe(['min' => ['amount' => '100.00', 'currency' => 'AED'], 'max' => ['amount' => '150.00', 'currency' => 'AED']])
        ->and($facets['ratings'])->toBe([['min' => 4, 'count' => 1], ['min' => 3, 'count' => 2], ['min' => 2, 'count' => 2], ['min' => 1, 'count' => 2]])
        ->and($facets['attributes'])->toBe([
            ['key' => 'colour', 'values' => [['value' => 'Blue', 'count' => 1], ['value' => 'Red', 'count' => 1]]],
            ['key' => 'size', 'values' => [['value' => 'M', 'count' => 1]]],
        ]);
});

it('lists brands with product counts, optionally within a category', function () {
    $phones = Category::factory()->create(['slug' => 'phones']);
    $nova = Brand::factory()->create(['slug' => 'nova', 'name' => 'Nova']);
    $pulse = Brand::factory()->create(['slug' => 'pulse', 'name' => 'Pulse']);
    Brand::factory()->create(['slug' => 'empty', 'name' => 'Empty']);
    storefrontProduct('10.00', ['brand_id' => $nova->id, 'category_id' => $phones->id]);
    storefrontProduct('10.00', ['brand_id' => $nova->id]);
    storefrontProduct('10.00', ['brand_id' => $pulse->id]);

    $this->getJson('/api/v1/uae-en/brands')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0', ['slug' => 'nova', 'name' => 'Nova', 'logo' => null, 'product_count' => 2]);
    $this->getJson('/api/v1/uae-en/brands?category=phones')->assertJsonCount(1, 'data')->assertJsonPath('data.0.product_count', 1);
});

it('returns the full category tree with rolled-up product counts and a category by slug with breadcrumbs', function () {
    $electronics = Category::factory()->create(['slug' => 'electronics', 'name_en' => 'Electronics']);
    $mobiles = Category::factory()->childOf($electronics)->create(['slug' => 'mobiles', 'name_en' => 'Mobiles']);
    $android = Category::factory()->childOf($mobiles)->create(['slug' => 'android', 'name_en' => 'Android']);
    storefrontProduct('10.00', ['category_id' => $android->id]);
    storefrontProduct('10.00', ['category_id' => $android->id]);
    storefrontProduct('10.00', ['category_id' => $mobiles->id]);
    storefrontProduct('10.00', ['category_id' => $mobiles->id], stock: 0);

    $this->getJson('/api/v1/uae-en/categories/tree')->assertOk()
        ->assertJsonPath('data.0.slug', 'electronics')
        ->assertJsonPath('data.0.product_count', 3)
        ->assertJsonPath('data.0.children.0.product_count', 3)
        ->assertJsonPath('data.0.children.0.children.0.slug', 'android')
        ->assertJsonPath('data.0.children.0.children.0.product_count', 2);

    $this->getJson('/api/v1/uae-en/categories/mobiles')->assertOk()
        ->assertJsonPath('data.product_count', 3)
        ->assertJsonPath('data.breadcrumbs', [['slug' => 'electronics', 'name' => 'Electronics'], ['slug' => 'mobiles', 'name' => 'Mobiles']])
        ->assertJsonPath('data.children.0.slug', 'android')
        ->assertJsonPath('data.children.0.product_count', 2);
    $this->getJson('/api/v1/uae-en/products?category=electronics')->assertJsonPath('meta.total', 3);
    $this->getJson('/api/v1/uae-en/categories/nope')->assertNotFound()->assertJsonPath('error.code', 'not_found');
});

it('keeps catalogue facets to the market country', function () {
    storefrontProduct('10.00', ['brand_id' => Brand::factory()->create(['slug' => 'uae-only'])->id]);
    $this->actAsCountry('omn');

    $this->getJson('/api/v1/omn-en/brands')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/omn-en/products/facets')->assertOk()->assertJsonPath('data.brands', [])->assertJsonPath('meta.total', 0);
});
