<?php

use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductVariant;
use App\Domains\Catalog\Support\SearchText;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
| QC-015: search index rebuild (normalised product search text).
*/

beforeEach(function () {
    $this->setUpCountries();
    $this->country = $this->actAsCountry('uae');
});

it('normalises Arabic letter variants, diacritics and case', function () {
    expect(SearchText::normalise('ماكِينة قهوةٌ إسبريسو'))->toBe('ماكينه قهوه اسبريسو')
        ->and(SearchText::normalise('Coffee—MAKER, 2-in-1'))->toBe('coffee maker 2 in 1')
        ->and(SearchText::normalise('مستشفى'))->toBe('مستشفي');
});

it('keeps the search text current on save, finds products by brand, folded Arabic and barcode, and rebuilds nightly', function () {
    $brand = Brand::factory()->create(['name' => 'Brewly']);
    $listing = sellableListing('50.00', 5);
    $product = $listing->variant->product;
    $product->update(['brand_id' => $brand->id, 'title_ar' => 'ماكينة قهوة']);
    ProductVariant::query()->whereKey($listing->product_variant_id)->first()->update(['barcode' => '6291041500213']);

    expect($product->refresh()->search_text)->toContain('brewly')->toContain('ماكينه قهوه')->toContain('6291041500213');

    foreach (['brewly', 'مَاكينة قهوة', '6291041500213'] as $term) {
        $this->getJson('/api/v1/uae-en/products?q='.urlencode($term))->assertOk()->assertJsonPath('data.0.slug', $product->slug);
    }

    Product::query()->whereKey($product->id)->toBase()->update(['search_text' => null]);
    $this->artisan('nawy:search:rebuild-index')->assertSuccessful();
    expect($product->refresh()->search_text)->toContain('brewly')->and($product->search_indexed_at)->not->toBeNull();
});
