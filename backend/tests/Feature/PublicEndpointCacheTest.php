<?php

use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductVariant;
use App\Domains\Content\Models\AppContext;
use App\Domains\Content\Models\BlogCategory;
use App\Domains\Content\Models\HelpArticle;
use App\Domains\Content\Models\HelpCollection;
use App\Domains\Content\Models\StaticPage;
use App\Domains\Countries\Models\Country;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Vendors\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
| Small public answers are cached (countries, market context, brands, help
| collections, static pages, blog categories, app contexts) and forgotten as
| soon as a row behind them changes through the application. A write that
| bypasses the models proves the cache is really used.
*/

beforeEach(function () {
    $this->setUpCountries();
    $this->actAsCountry('uae');
});

it('caches the country list and market context until a country changes', function () {
    $this->getJson('/api/v1/countries')->assertOk();
    $this->getJson('/api/v1/uae-en/context')->assertOk()->assertJsonPath('data.name', 'United Arab Emirates');

    DB::table('countries')->where('code', 'uae')->update(['name_en' => 'Bypassed']);
    expect(collect($this->getJson('/api/v1/countries')->json('data'))->firstWhere('code', 'uae')['name'])->toBe('United Arab Emirates');
    $this->getJson('/api/v1/uae-en/context')->assertJsonPath('data.name', 'United Arab Emirates');

    Country::query()->where('code', 'uae')->firstOrFail()->update(['name_en' => 'Emirates']);
    expect(collect($this->getJson('/api/v1/countries')->json('data'))->firstWhere('code', 'uae')['name'])->toBe('Emirates');
    $this->getJson('/api/v1/uae-en/context')->assertJsonPath('data.name', 'Emirates');
});

it('caches the brand list per country until a brand changes, and never caches a search', function () {
    $nova = Brand::factory()->create(['slug' => 'nova', 'name' => 'Nova']);
    $variant = ProductVariant::factory()->for(Product::factory()->create(['brand_id' => $nova->id]))->create(['option_values' => ['colour' => 'Black']]);
    app(InventoryLedger::class)->move(Listing::factory()->for(Vendor::factory()->create())->for($variant, 'variant')->active()->create(['price' => '10.00']), 5, 'restock');

    $this->getJson('/api/v1/uae-en/brands')->assertJsonPath('data.0.name', 'Nova');
    DB::connection($nova->getConnectionName())->table('brands')->where('id', $nova->id)->update(['name' => 'Bypassed']);
    $this->getJson('/api/v1/uae-en/brands')->assertJsonPath('data.0.name', 'Nova');
    $this->getJson('/api/v1/uae-en/brands?q=Byp')->assertJsonPath('data.0.name', 'Bypassed');

    $nova->refresh()->update(['name' => 'Nova Two']);
    $this->getJson('/api/v1/uae-en/brands')->assertJsonPath('data.0.name', 'Nova Two');
});

it('caches help collections until a collection or article changes, but not for view counts', function () {
    $collection = HelpCollection::query()->create(['hub' => 'help_center', 'audience' => 'customer', 'slug' => 'orders', 'title_en' => 'Orders', 'title_ar' => 'الطلبات']);
    $article = HelpArticle::query()->create(['collection_id' => $collection->id, 'hub' => 'help_center', 'slug' => 'track', 'title_en' => 'Track', 'title_ar' => 'تتبع', 'body_en' => 'x', 'body_ar' => 'x', 'is_published' => true]);

    $this->getJson('/api/v1/uae-en/help/help_center/collections')->assertJsonPath('data.0.articles_count', 1);
    $article->increment('views');
    $this->getJson('/api/v1/uae-en/help/help_center/collections')->assertJsonPath('data.0.articles.0.views', 0);

    $article->update(['is_published' => false]);
    $this->getJson('/api/v1/uae-en/help/help_center/collections')->assertJsonPath('data.0.articles_count', 0);

    $collection->update(['title_en' => 'Your orders']);
    $this->getJson('/api/v1/uae-en/help/help_center/collections')->assertJsonPath('data.0.title', 'Your orders');
});

it('caches static pages (and their 404) until a page changes', function () {
    $this->getJson('/api/v1/uae-en/static-pages/cache-test-page')->assertNotFound();
    $page = StaticPage::query()->create(['slug' => 'cache-test-page', 'audience' => 'storefront', 'title_en' => 'Shipping', 'title_ar' => 'الشحن', 'body_en' => 'Fast', 'body_ar' => 'سريع']);
    $this->getJson('/api/v1/uae-en/static-pages/cache-test-page')->assertOk()->assertJsonPath('data.body', 'Fast');

    DB::table('static_pages')->where('id', $page->id)->update(['body_en' => 'Bypassed']);
    $this->getJson('/api/v1/uae-en/static-pages/cache-test-page')->assertJsonPath('data.body', 'Fast');

    $page->refresh()->update(['body_en' => 'Faster']);
    $this->getJson('/api/v1/uae-en/static-pages/cache-test-page')->assertJsonPath('data.body', 'Faster');
});

it('caches blog categories and app contexts until they change', function () {
    $category = BlogCategory::query()->create(['slug' => 'tips', 'name_en' => 'Tips', 'name_ar' => 'نصائح']);
    $this->getJson('/api/v1/uae-en/blog/categories')->assertJsonPath('data.0.name', 'Tips');
    $category->update(['name_en' => 'Selling tips']);
    $this->getJson('/api/v1/uae-en/blog/categories')->assertJsonPath('data.0.name', 'Selling tips')->assertJsonPath('meta.tree.0.name', 'Selling tips');

    // The first answer seeds the default contexts (which itself forgets the cache); the next one is cached.
    $this->getJson('/api/v1/uae-en/app/contexts')->assertOk();
    $first = $this->getJson('/api/v1/uae-en/app/contexts')->assertOk()->json('data.0');
    $context = AppContext::query()->where('key', $first['key'])->firstOrFail();
    DB::connection($context->getConnectionName())->table('app_contexts')->where('id', $context->id)->update(['name_en' => 'Bypassed']);
    $this->getJson('/api/v1/uae-en/app/contexts')->assertJsonPath('data.0.name', $first['name']);

    $context->refresh()->update(['name_en' => 'Everything']);
    $this->getJson('/api/v1/uae-en/app/contexts')->assertJsonPath('data.0.name', 'Everything');
});
