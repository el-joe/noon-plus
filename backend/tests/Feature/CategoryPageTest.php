<?php

use App\Domains\Advertising\Enums\AdBookingStatus;
use App\Domains\Advertising\Models\AdBooking;
use App\Domains\Advertising\Models\AdSlot;
use App\Domains\Advertising\Support\AdvertiserBilling;
use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Content\Support\BlockResolver;
use App\Domains\Content\Support\DevLayouts\CategoryLayout;
use App\Domains\Content\Support\Pages;
use App\Domains\Listings\Models\Listing;
use App\Domains\Vendors\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/*
| Category page (design category-page-target.webp): the rail filters and
| facets added for it (fulfilled by supermall, arrives by, grade, price drop,
| new arrivals, rolled-up category counts), the offer captions of
| sub-category tiles, the category page blocks' data, the sponsored brand row
| and the CategoryLayout zones.
*/

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
    Cache::flush();
});

/**
 * A sellable listing of a product in `$category`.
 *
 * @param  array<string, mixed>  $listing
 * @param  array<string, mixed>  $product
 */
function catListing(Category $category, string $title, string $price, array $listing = [], array $product = [], ?Vendor $vendor = null): Listing
{
    $offer = sellableListing($price, 5, $listing, $vendor);
    $offer->variant->product->update(['title_en' => $title, 'title_ar' => $title, 'category_id' => $category->id] + $product);

    return $offer->refresh();
}

/**
 * electronics › mobiles › smartphones, electronics › audio.
 *
 * @return array{0: Category, 1: Category, 2: Category, 3: Category}
 */
function catTree(): array
{
    $root = Category::factory()->create(['slug' => 'electronics', 'name_en' => 'Electronics', 'name_ar' => 'الإلكترونيات']);
    $mobiles = Category::factory()->create(['slug' => 'mobiles', 'name_en' => 'Mobiles', 'name_ar' => 'الجوالات', 'parent_id' => $root->id, 'sort_order' => 1]);
    $phones = Category::factory()->create(['slug' => 'smartphones', 'name_en' => 'Smartphones', 'name_ar' => 'الهواتف', 'parent_id' => $mobiles->id]);
    $audio = Category::factory()->create(['slug' => 'audio', 'name_en' => 'Audio', 'name_ar' => 'الصوتيات', 'parent_id' => $root->id, 'sort_order' => 2]);

    return [$root, $mobiles, $phones, $audio];
}

it('filters and counts arrives by, grade, price drop, new arrivals, supermall and the category tree', function () {
    [$root, $mobiles, $phones, $audio] = catTree();
    $brand = Brand::factory()->create(['slug' => 'soundmax', 'name' => 'SoundMax']);
    $official = Vendor::factory()->create(['slug' => 'soundmax-official']);

    $fast = catListing($phones, 'Nova X12', '1000.00', ['fulfilment' => 'fbn', 'handling_days' => 3]);
    $tomorrow = catListing($phones, 'Renewed phone', '500.00', ['condition' => 'refurbished', 'grade' => 'A', 'handling_days' => 1]);
    $dropped = catListing($audio, 'SoundMax Buds', '200.00', ['handling_days' => 2, 'previous_price' => '260.00', 'price_dropped_at' => now()->subDays(3)], ['brand_id' => $brand->id], $official);
    $old = catListing($audio, 'Old speaker', '90.00', ['handling_days' => 5, 'previous_price' => '120.00', 'price_dropped_at' => now()->subDays(60)]);
    Product::query()->whereKey($old->variant->product_id)->update(['created_at' => now()->subDays(90)]);
    Product::query()->whereKey($tomorrow->variant->product_id)->update(['created_at' => now()->subDays(45)]);

    $facets = $this->getJson('/api/v1/uae-en/products/facets?category=electronics')->assertOk()->assertJsonPath('meta.total', 4);
    expect($facets->json('data.arrives'))->toBe([['value' => 'hour', 'count' => 1], ['value' => 'tomorrow', 'count' => 2], ['value' => 'two_days', 'count' => 3]])
        ->and($facets->json('data.grades'))->toBe([['value' => 'A', 'count' => 1]])
        ->and($facets->json('data.price_drop'))->toBe(1)
        ->and($facets->json('data.new_arrivals'))->toBe(2)
        ->and($facets->json('data.fulfilment.0'))->toBe(['value' => 'supermall', 'count' => 1])
        ->and(collect($facets->json('data.categories'))->pluck('count', 'slug')->all())->toEqual(['electronics' => 4, 'mobiles' => 2, 'audio' => 2, 'smartphones' => 2]);

    $titles = fn (string $query): array => collect($this->getJson('/api/v1/uae-en/products?category=electronics&'.$query)->assertOk()->json('data'))->pluck('title')->sort()->values()->all();
    expect($titles('arrives=hour'))->toBe(['Nova X12'])
        ->and($titles('arrives=tomorrow'))->toBe(['Nova X12', 'Renewed phone'])
        ->and($titles('grade=A'))->toBe(['Renewed phone'])
        ->and($titles('price_drop=1'))->toBe(['SoundMax Buds'])
        ->and($titles('new_arrivals=1'))->toBe(['Nova X12', 'SoundMax Buds'])
        ->and($titles('fulfilment=supermall'))->toBe(['SoundMax Buds'])
        ->and($titles('fulfilment=supermall,fbn'))->toBe(['Nova X12', 'SoundMax Buds']);

    // A facet is counted with every other filter: the category tree ignores the category but keeps grade.
    expect(collect($this->getJson('/api/v1/uae-en/products/facets?category=audio&grade=A')->json('data.categories'))->pluck('count', 'slug')->all())
        ->toEqual(['electronics' => 1, 'mobiles' => 1, 'smartphones' => 1]);
    $this->getJson('/api/v1/uae-en/products?arrives=someday')->assertUnprocessable();
    $this->getJson('/api/v1/uae-en/products?price_drop=maybe')->assertUnprocessable();

    // Out of stock offers stay out of every count and filter.
    $fast->update(['status' => 'paused']);
    $this->getJson('/api/v1/uae-en/products/facets?category=electronics')->assertJsonPath('meta.total', 3)->assertJsonPath('data.arrives.0', ['value' => 'tomorrow', 'count' => 1]);
});

it('sends the lowest price and the deepest discount of each sub-category for the tiles', function () {
    [$root, $mobiles, $phones, $audio] = catTree();
    catListing($phones, 'Nova X12', '1000.00', ['sale_price' => '600.00']);
    catListing($phones, 'Nova lite', '499.00');
    catListing($audio, 'Buds', '120.00');
    Category::factory()->create(['slug' => 'cameras', 'parent_id' => $root->id]);

    $category = $this->getJson('/api/v1/uae-en/categories/electronics')->assertOk();
    $children = collect($category->json('data.children'))->keyBy('slug');

    expect($category->json('data.max_discount'))->toBe(40)
        ->and($category->json('data.min_price.amount'))->toBe('120.00')
        ->and($children['mobiles']['max_discount'])->toBe(40)
        ->and($children['mobiles']['min_price']['amount'])->toBe('499.00')
        ->and($children['audio']['max_discount'])->toBe(0)
        ->and($children['cameras']['min_price'])->toBeNull();
});

it('resolves the category page blocks from live offers', function () {
    [$root, $mobiles, $phones, $audio] = catTree();
    $nova = Brand::factory()->create(['slug' => 'nova', 'name' => 'Nova']);
    $sound = Brand::factory()->create(['slug' => 'soundmax', 'name' => 'SoundMax']);
    catListing($phones, 'Nova X12', '1000.00', ['sale_price' => '700.00'], ['brand_id' => $nova->id]);
    catListing($phones, 'Nova lite', '80.00', [], ['brand_id' => $nova->id]);
    catListing($audio, 'SoundMax Buds', '60.00', ['sale_price' => '45.00'], ['brand_id' => $sound->id]);

    $zones = app(BlockResolver::class)->resolve(['top' => [
        ['id' => 'tiles', 'type' => 'category_promo_tiles', 'settings' => ['hero' => ['title' => 'Mobiles', 'category' => 'mobiles'], 'tiles' => [['title' => 'Audio', 'category' => 'audio']]]],
        ['id' => 'brands', 'type' => 'category_brand_tiles', 'settings' => ['category' => 'electronics']],
        ['id' => 'gifts', 'type' => 'category_gift_band', 'settings' => ['category' => 'electronics', 'max_price' => '99']],
        ['id' => 'deals', 'type' => 'category_deal_columns', 'settings' => ['category' => 'electronics']],
    ]]);
    [$tiles, $brands, $gifts, $deals] = $zones['top'];

    expect($tiles['data']['captions']['mobiles']['max_discount'])->toBe(30)
        ->and($tiles['data']['captions']['audio'])->toBe(['min_price' => ['amount' => '45.00', 'currency' => 'AED'], 'max_discount' => 25])
        ->and(collect($brands['data']['tiles'])->pluck('product_count', 'slug')->all())->toBe(['nova' => 2, 'soundmax' => 1])
        ->and($brands['data']['tiles'][0]['min_price']['amount'])->toBe('80.00')
        ->and(collect($gifts['data']['products'])->pluck('title')->sort()->values()->all())->toBe(['Nova lite', 'SoundMax Buds'])
        ->and($gifts['data']['categories'])->toEqual(['smartphones' => 'Smartphones', 'audio' => 'Audio'])
        ->and(collect($deals['data']['columns'])->pluck('product.title', 'slug')->all())->toBe(['mobiles' => 'Nova X12', 'audio' => 'SoundMax Buds']);
});

it('serves the sponsored brand row of a category with the brand products, only while booked', function () {
    [$root, $mobiles, $phones, $audio] = catTree();
    $sound = Brand::factory()->create(['slug' => 'soundmax', 'name' => 'SoundMax']);
    catListing($audio, 'SoundMax Buds', '60.00', [], ['brand_id' => $sound->id]);
    catListing($phones, 'Other phone', '600.00');
    $slot = AdSlot::query()->create(['code' => 'cat-brand-electronics', 'name' => 'Electronics brand row', 'placement' => 'sponsored_brand_banner', 'category_id' => $root->id, 'price_per_day' => '50.00', 'max_concurrent' => 2]);
    AdSlot::query()->create(['code' => 'cat-brand-fashion', 'name' => 'Fashion brand row', 'placement' => 'sponsored_brand_banner', 'category_id' => Category::factory()->create()->id, 'price_per_day' => '50.00']);
    AdBooking::query()->forceCreate([
        'number' => 'AB-1', 'ad_slot_id' => $slot->id, 'advertiser_type' => AdvertiserBilling::VENDOR, 'advertiser_id' => Vendor::factory()->create()->id,
        'starts_on' => today()->subDay(), 'ends_on' => today()->addDays(5), 'days' => 7, 'currency' => 'AED', 'amount' => '350.00',
        'link_url' => '/brands/soundmax', 'headline' => 'Hear every detail', 'status' => AdBookingStatus::Active, 'payment_status' => 'paid',
    ]);

    $this->getJson('/api/v1/uae-en/ads/placements/sponsored_brand_banner?category=smartphones&with=products')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.headline', 'Hear every detail')
        ->assertJsonPath('data.0.label', 'Ad')
        ->assertJsonPath('data.0.brand.name', 'SoundMax')
        ->assertJsonPath('data.0.products.0.title', 'SoundMax Buds');
    $this->getJson('/api/v1/uae-en/ads/placements/sponsored_brand_banner?category=electronics')->assertOk()->assertJsonMissingPath('data.0.products');

    AdBooking::query()->update(['status' => AdBookingStatus::Completed]);
    $this->getJson('/api/v1/uae-en/ads/placements/sponsored_brand_banner?category=electronics&with=products')->assertOk()->assertJsonCount(0, 'data');
});

it('builds a publishable category page layout in English and Arabic', function () {
    [$root, $mobiles, $phones, $audio] = catTree();
    catListing($phones, 'Nova X12', '1000.00', ['sale_price' => '800.00']);
    catListing($audio, 'Buds', '60.00');
    $pages = app(Pages::class);

    foreach (['en', 'ar'] as $locale) {
        $zones = app(CategoryLayout::class)->zones($this->country, $root, $locale);
        $page = $pages->create(['template' => 'category', 'slug' => 'electronics', 'locale' => $locale, 'title' => 'Electronics'], 1);
        $revision = $pages->saveDraft($page, $zones, 1);

        expect(collect($revision->zones['top'])->pluck('type')->all())->toBe(['category_hero', 'subcategory_tiles', 'product_carousel', 'category_deal_columns', 'category_promo_tiles', 'category_brand_tiles', 'category_gift_band', 'sponsored_brand_showcase'])
            ->and(collect($revision->zones['listing'])->pluck('type')->all())->toBe(['subcategory_chips', 'product_listing'])
            ->and(collect($revision->zones['bottom'])->pluck('type')->all())->toBe(['seo_text', 'popular_searches']);
    }

    $ar = app(CategoryLayout::class)->zones($this->country, $root, 'ar');
    expect($ar['top'][0]['settings']['slides'][0]['sticker'])->toBe('خصم حتى 20٪')
        ->and($ar['top'][6]['settings']['sticker'])->toBe('أقل من AED 99')
        ->and($ar['bottom'][0]['settings']['title'])->toBe('تسوّق إلكتروني متميّز في الإمارات');
});
