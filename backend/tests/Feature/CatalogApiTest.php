<?php

use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductVariant;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Vendors\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->actAsCountry('uae');
});

/**
 * An active listing with stock for a product (creates the variant if needed).
 */
function offer(Product $product, string $price, int $stock = 5, ?string $salePrice = null, string $rating = '4.50'): Listing
{
    $variant = $product->variants()->first() ?? ProductVariant::factory()->for($product)->create();
    $listing = Listing::factory()
        ->for(Vendor::factory()->state(['rating' => $rating]))
        ->for($variant, 'variant')
        ->active()
        ->create(['price' => $price, 'sale_price' => $salePrice]);

    if ($stock > 0) {
        app(InventoryLedger::class)->move($listing, $stock, 'restock');
    }

    return $listing;
}

it('returns the active category tree with children', function () {
    $electronics = Category::factory()->create(['slug' => 'electronics', 'sort_order' => 1]);
    Category::factory()->childOf($electronics)->create(['slug' => 'mobiles']);
    Category::factory()->childOf($electronics)->create(['slug' => 'hidden', 'is_active' => false]);
    Category::factory()->create(['slug' => 'archived-root', 'is_active' => false]);

    $this->getJson('/api/v1/uae-en/categories')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'electronics')
        ->assertJsonCount(1, 'data.0.children')
        ->assertJsonPath('data.0.children.0.slug', 'mobiles');
});

it('lists only products that can be bought, at their buy-box price', function () {
    $earbuds = Product::factory()->create(['title_en' => 'Earbuds Pro']);
    offer($earbuds, '299.00');
    offer($earbuds, '249.00');
    offer(Product::factory()->create(['title_en' => 'Sold out speaker']), '99.00', stock: 0);
    Product::factory()->create(['title_en' => 'No offers yet']);

    $this->getJson('/api/v1/uae-en/products')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Earbuds Pro')
        ->assertJsonPath('data.0.price', ['amount' => '249.00', 'currency' => 'AED'])
        ->assertJsonPath('meta.total', 1);
});

it('filters by parent category, brand, text and price range and sorts by price', function () {
    $electronics = Category::factory()->create(['slug' => 'electronics']);
    $audio = Category::factory()->childOf($electronics)->create(['slug' => 'audio']);
    $soundmax = Brand::factory()->create(['slug' => 'soundmax']);

    offer(Product::factory()->for($audio)->for($soundmax)->create(['title_en' => 'Earbuds Pro']), '249.00');
    offer(Product::factory()->for($audio)->for($soundmax)->create(['title_en' => 'Studio Headphones']), '399.00');
    offer(Product::factory()->for($audio)->create(['title_en' => 'Other Earbuds']), '89.00');
    offer(Product::factory()->create(['title_en' => 'Kettle']), '120.00');

    $this->getJson('/api/v1/uae-en/products?category=electronics&brand=soundmax&sort=price_desc')
        ->assertJsonPath('data.0.title', 'Studio Headphones')
        ->assertJsonPath('data.1.title', 'Earbuds Pro');

    $this->getJson('/api/v1/uae-en/products?q=earbuds&min_price=100&max_price=300')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Earbuds Pro');
});

it('shows the cheapest in-stock offer in the buy box and the rest as other offers', function () {
    $product = Product::factory()->create(['slug' => 'earbuds-pro']);
    offer($product, '279.00', rating: '4.90');
    $winner = offer($product, '299.00', salePrice: '249.00');
    offer($product, '199.00', stock: 0);

    $this->getJson('/api/v1/uae-en/products/earbuds-pro')
        ->assertOk()
        ->assertJsonPath('data.buy_box.listing_id', $winner->id)
        ->assertJsonPath('data.buy_box.price.amount', '249.00')
        ->assertJsonPath('data.buy_box.was_price.amount', '299.00')
        ->assertJsonCount(1, 'data.other_offers')
        ->assertJsonPath('data.from_price.amount', '249.00');
});

it('breaks a price tie in favour of the better-rated seller', function () {
    $product = Product::factory()->create(['slug' => 'tie']);
    offer($product, '100.00', rating: '4.10');
    $betterSeller = offer($product, '100.00', rating: '4.80');

    $this->getJson('/api/v1/uae-en/products/tie')->assertJsonPath('data.buy_box.listing_id', $betterSeller->id);
});

it('shows prices with three decimals in Oman', function () {
    $this->actAsCountry('omn');
    offer(Product::factory()->create(['slug' => 'oud']), '42.5');

    $this->getJson('/api/v1/omn-en/products/oud')->assertJsonPath('data.buy_box.price', ['amount' => '42.500', 'currency' => 'OMR']);
});

it('keeps each country catalogue separate', function () {
    offer(Product::factory()->create(['slug' => 'uae-only']), '10.00');

    $this->getJson('/api/v1/ksa-en/products/uae-only')->assertNotFound();
    $this->getJson('/api/v1/ksa-en/products')->assertJsonCount(0, 'data');
});

it('returns 404 for an unknown product', function () {
    $this->getJson('/api/v1/uae-en/products/nope')->assertNotFound();
});
