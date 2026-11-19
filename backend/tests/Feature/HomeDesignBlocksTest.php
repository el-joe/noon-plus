<?php

use App\Domains\Catalog\Http\Resources\ProductCardResource;
use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductVariant;
use App\Domains\Content\Support\BlockRegistry;
use App\Domains\Content\Support\BlockResolver;
use App\Domains\Content\Support\DevLayouts\HomeLayout;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Coupons\Enums\CouponTarget;
use App\Domains\Coupons\Enums\CouponType;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\FlashSales\Enums\FlashSaleEventStatus;
use App\Domains\FlashSales\Enums\FlashSaleSubmissionStatus;
use App\Domains\FlashSales\Models\FlashSaleEvent;
use App\Domains\FlashSales\Models\FlashSaleSubmission;
use App\Domains\Listings\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->country = $this->actAsCountry('uae');
    Cache::flush();
});

/**
 * A sellable offer (10 in stock) for a product.
 *
 * @param  array<string, mixed>  $attributes
 */
function offerFor(Product $product, string $price = '100.00', array $attributes = [], int $stock = 10): Listing
{
    $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

    return sellableListing($price, $stock, ['product_variant_id' => $variant->id] + $attributes);
}

/**
 * @param  array<string, mixed>  $settings
 * @return array<int, array<string, mixed>>
 */
function sourceCards(array $settings): array
{
    Cache::flush();

    return app(BlockResolver::class)->resolve(['main' => [['type' => 'product_carousel', 'settings' => $settings]]])['main'][0]['data']['products'];
}

describe('design product card fields', function () {
    it('sends was-price, discount, the fastest delivery, the offer count, quick add and the deal badge', function () {
        $product = Product::factory()->create();
        $buyBox = offerFor($product, '200.00', ['sale_price' => '150.00', 'handling_days' => 2, 'fulfilment' => 'fbm']);
        offerFor($product, '180.00', ['handling_days' => 0, 'fulfilment' => 'fbn']);
        offerFor($product, '90.00', [], stock: 0);

        $card = (new ProductCardResource($product->fresh()))->resolve(request());

        expect($card['price'])->toBe(['amount' => '150.00', 'currency' => 'AED'])
            ->and($card['was_price'])->toBe(['amount' => '200.00', 'currency' => 'AED'])
            ->and($card['discount_percent'])->toBe(25)
            ->and($card['listing_id'])->toBe($buyBox->id)
            ->and($card['offers_count'])->toBe(2)
            ->and($card['delivery'])->toBe(['express' => true, 'key' => 'today'])
            ->and(array_column($card['badges'], 'key'))->toContain('deal');
    });

    it('strikes through the price before a drop and has no delivery without offers', function () {
        $dropped = Product::factory()->create();
        offerFor($dropped, '80.00', ['previous_price' => '100.00', 'price_dropped_at' => now(), 'handling_days' => 1]);
        $unsold = Product::factory()->create();

        $cards = ProductCardResource::collection(Product::query()->whereKey([$dropped->id, $unsold->id])->orderBy('id')->get())->resolve(request());

        expect($cards[0]['was_price']['amount'])->toBe('100.00')
            ->and($cards[0]['discount_percent'])->toBe(20)
            ->and($cards[0]['delivery'])->toBe(['express' => false, 'key' => 'tomorrow'])
            ->and(array_column($cards[0]['badges'], 'key'))->not->toContain('deal')
            ->and($cards[1]['price'])->toBeNull()
            ->and($cards[1]['delivery'])->toBeNull()
            ->and($cards[1]['offers_count'])->toBe(0);
    });

    it('marks fast sellers trending unless they are ranked bestsellers', function () {
        config(['nawy.discovery.trending_min_units' => 25]);
        $trending = Product::factory()->create(['units_sold_30d' => 40]);
        $bestseller = Product::factory()->create(['units_sold_30d' => 400, 'bestseller_rank' => 1]);

        expect(array_column((new ProductCardResource($trending))->resolve(request())['badges'], 'key'))->toContain('trending')
            ->and(array_column((new ProductCardResource($bestseller))->resolve(request())['badges'], 'key'))->toContain('bestseller')->not->toContain('trending');
    });
});

describe('server-side product sources', function () {
    it('lists a category with its sub-categories, one card per sellable product', function () {
        $root = Category::factory()->create(['slug' => 'electronics']);
        $leaf = Category::factory()->childOf(Category::factory()->childOf($root)->create())->create();
        $inLeaf = Product::factory()->create(['category_id' => $leaf->id, 'bestseller_rank' => 2]);
        offerFor($inLeaf, '50.00');
        offerFor($inLeaf, '60.00');
        $inRoot = Product::factory()->create(['category_id' => $root->id, 'bestseller_rank' => 1]);
        offerFor($inRoot);
        $outOfStock = Product::factory()->create(['category_id' => $leaf->id]);
        offerFor($outOfStock, stock: 0);
        offerFor(Product::factory()->create());

        $cards = sourceCards(['source' => 'category', 'category' => 'electronics']);

        expect(array_column($cards, 'slug'))->toBe([$inRoot->slug, $inLeaf->slug])
            ->and($cards[1]['price']['amount'])->toBe('50.00');
    });

    it('orders bestsellers, top rated, trending and brand products', function () {
        $brand = Brand::factory()->create(['slug' => 'nova']);
        $second = Product::factory()->create(['bestseller_rank' => 2, 'rating_average' => '4.90', 'rating_count' => 10, 'brand_id' => $brand->id]);
        $first = Product::factory()->create(['bestseller_rank' => 1, 'rating_average' => '4.10', 'rating_count' => 300]);
        $fast = Product::factory()->create(['units_sold_30d' => 90]);
        foreach ([$second, $first, $fast] as $product) {
            offerFor($product);
        }

        expect(array_column(sourceCards(['source' => 'bestsellers']), 'slug'))->toBe([$first->slug, $second->slug, $fast->slug])
            ->and(array_column(sourceCards(['source' => 'top_rated']), 'slug'))->toBe([$second->slug, $first->slug])
            ->and(array_column(sourceCards(['source' => 'trending']), 'slug'))->toBe([$fast->slug])
            ->and(array_column(sourceCards(['source' => 'brand', 'brand' => 'nova']), 'slug'))->toBe([$second->slug])
            ->and(sourceCards(['source' => 'brand']))->toBe([]);
    });

    it('lists deals: a sale price or a running flash deal', function () {
        $onSale = Product::factory()->create();
        offerFor($onSale, '100.00', ['sale_price' => '80.00']);
        $flash = Product::factory()->create();
        $listing = offerFor($flash, '300.00');
        $event = FlashSaleEvent::factory()->running()->inStatus(FlashSaleEventStatus::Live)->create();
        FlashSaleSubmission::query()->create(['flash_sale_event_id' => $event->id, 'listing_id' => $listing->id, 'vendor_id' => $listing->vendor_id, 'original_price' => '300.00', 'flash_price' => '200.00', 'quantity_cap' => 5])
            ->forceFill(['status' => FlashSaleSubmissionStatus::Live])->save();
        offerFor(Product::factory()->create());

        $cards = sourceCards(['source' => 'deals']);

        expect(array_column($cards, 'slug'))->toEqualCanonicalizing([$onSale->slug, $flash->slug])
            ->and(collect($cards)->every(fn (array $card): bool => in_array('deal', array_column($card['badges'], 'key'), true)))->toBeTrue();
    });

    it('lists what a coupon targets and nothing for an unknown coupon', function () {
        $fragrances = Category::factory()->create(['slug' => 'fragrances']);
        $womens = Category::factory()->childOf($fragrances)->create();
        $perfume = Product::factory()->create(['category_id' => $womens->id]);
        offerFor($perfume);
        offerFor(Product::factory()->create());
        Coupon::factory()->create(['code' => 'SCENT10', 'target_type' => CouponTarget::Category, 'target_ids' => [$fragrances->id]]);

        expect(array_column(sourceCards(['source' => 'coupon', 'coupon' => 'scent10']), 'slug'))->toBe([$perfume->slug])
            ->and(sourceCards(['source' => 'coupon', 'coupon' => 'NOPE']))->toBe([]);
    });

    it('spreads recommendations over categories and fills up from a fallback source', function () {
        [$phones, $shoes] = Category::factory()->count(2)->create();
        $topPhones = collect(range(1, 3))->map(fn (int $i) => tap(Product::factory()->create(['category_id' => $phones->id, 'rating_average' => '4.90', 'rating_count' => 1000 - $i]), fn ($p) => offerFor($p)));
        $shoe = tap(Product::factory()->create(['category_id' => $shoes->id, 'rating_average' => '3.00', 'rating_count' => 5]), fn ($p) => offerFor($p));

        $recommended = array_column(sourceCards(['source' => 'recommended', 'max_items' => 2]), 'slug');
        $filled = array_column(sourceCards(['source' => 'trending', 'fallback_source' => 'top_rated', 'max_items' => 3]), 'slug');

        expect($recommended)->toBe([$topPhones[0]->slug, $shoe->slug])
            ->and($filled)->toHaveCount(3);
    });
});

describe('design block data', function () {
    it('resolves category tiles, sub-category showcases and the live pill', function () {
        $fashion = Category::factory()->create(['slug' => 'womens-fashion', 'image_path' => 'products/demo/product-abaya.png']);
        $dresses = Category::factory()->childOf($fashion)->create(['slug' => 'dresses', 'name_en' => 'Dresses']);
        Category::factory()->childOf($fashion)->create(['slug' => 'empty-shelf']);
        Product::factory()->create(['category_id' => $dresses->id]);

        $zones = app(BlockResolver::class)->resolve(['main' => [
            ['type' => 'tile_scroller', 'settings' => ['live_pill' => true, 'items' => [['label' => 'Deals', 'link' => '/deals'], ['category' => 'womens-fashion'], ['category' => 'gone']]]],
            ['type' => 'category_showcase', 'settings' => ['category' => 'womens-fashion']],
        ]]);

        $strip = $zones['main'][0]['data'];
        expect(array_column($strip['tiles'], 'label'))->toBe(['Deals', $fashion->name_en])
            ->and($strip['tiles'][1]['image'])->toEndWith('/storage/products/demo/product-abaya.png')
            ->and($strip['tiles'][1]['link'])->toBe('/womens-fashion')
            ->and($strip['live'])->toBeNull()
            ->and($zones['main'][1]['data']['tiles'])->toBe([['label' => 'Dresses', 'image' => null, 'link' => '/womens-fashion/dresses', 'category' => 'dresses']]);
    });

    it('embeds the flash sale of a deal grid by slug, inside a panel row, and keeps only live coupon codes', function () {
        $listing = offerFor(Product::factory()->create(), '200.00');
        $event = FlashSaleEvent::factory()->running()->inStatus(FlashSaleEventStatus::Live)->create(['name' => 'Big mega deals']);
        FlashSaleSubmission::query()->create(['flash_sale_event_id' => $event->id, 'listing_id' => $listing->id, 'vendor_id' => $listing->vendor_id, 'original_price' => '200.00', 'flash_price' => '150.00', 'quantity_cap' => 5])
            ->forceFill(['status' => FlashSaleSubmissionStatus::Live])->save();
        Coupon::factory()->create(['code' => 'GLOW']);
        Coupon::factory()->create(['code' => 'OLD', 'ends_at' => now()->subDay()]);

        $zones = app(BlockResolver::class)->resolve(['main' => [
            ['type' => 'panel_row', 'settings' => ['columns' => [['width' => 1, 'block' => ['type' => 'deal_grid', 'settings' => ['flash_sale_slug' => $event->refresh()->slug]]]]]],
            ['type' => 'offer_cards', 'settings' => ['items' => [['code' => 'glow'], ['code' => 'OLD']]]],
        ]]);

        $sale = $zones['main'][0]['settings']['columns'][0]['block']['data']['sale'];
        expect($sale['slug'])->toBe($event->slug)
            ->and($sale['deals'][0]['price']['amount'])->toBe('150.00')
            ->and($zones['main'][1]['data']['codes'])->toBe(['GLOW'])
            ->and(BlockRegistry::missing('deal_grid', ['flash_sale_slug' => $event->slug]))->toBe([])
            ->and(BlockRegistry::missing('banner_full', ['title' => 'Vehicles']))->toBe([]);
    });
});

describe('home layout', function () {
    it('builds a publishable home for the country and language with converted amounts', function () {
        $kwt = Country::query()->where('code', 'kwt')->firstOrFail();
        $context = app(CountryContext::class);
        $layout = app(HomeLayout::class);

        $context->runAs($kwt, function (): void {
            Category::factory()->create(['slug' => 'mobiles']);
            Category::factory()->create(['slug' => 'womens-fashion']);
            Coupon::factory()->create(['code' => 'FREEBUDS', 'target_type' => CouponTarget::Category, 'target_ids' => []]);
            Coupon::factory()->create(['code' => 'SALE200', 'type' => CouponType::FixedAmount, 'value' => '20.000', 'min_order_amount' => '100.000']);
        });

        [$en, $ar] = $context->runAs($kwt, fn (Country $country): array => [$layout->zones($country, 'en'), $layout->zones($country, 'ar')]);
        $byId = collect($en['main'])->keyBy('id');

        expect($layout->amount(249))->toBe('20.950')
            ->and($byId['home-free-earbuds']['settings']['title'])->toBe('Free earbuds worth KWD 20.950 with selected smartphones')
            ->and($byId['home-free-earbuds']['settings']['source'])->toBe('coupon')
            ->and($byId['home-seo']['settings']['title'])->toBe('Superior online shopping in Kuwait')
            ->and($byId['home-savings']['settings']['items'][0])->toMatchArray(['code' => 'SALE200', 'title' => 'Extra KWD 20.000 off', 'caption' => 'On orders over KWD 100.000'])
            ->and($byId->has('home-showcase-womens-fashion'))->toBeTrue()
            ->and($byId->has('home-showcase-beauty'))->toBeFalse()
            ->and(collect($ar['main'])->firstWhere('id', 'home-seo')['settings']['title'])->toBe('تسوّق إلكتروني متميّز في الكويت');

        foreach ([...$en['main'], ...$ar['main']] as $block) {
            expect(BlockRegistry::has($block['type']))->toBeTrue()
                ->and(BlockRegistry::missing($block['type'], $block['settings']))->toBe([]);
        }
    });

    it('rounds copy amounts per currency', function (string $currency, float $aed, string $expected) {
        expect(app(HomeLayout::class)->withCurrency($currency)->money($aed))->toBe($expected);
    })->with([
        ['AED', 249, 'AED 249'],
        ['SAR', 249, 'SAR 255'],
        ['OMR', 249, 'OMR 25.400'],
        ['BHD', 249, 'BHD 25.600'],
        ['QAR', 249, 'QAR 247'],
        ['EGP', 249, 'EGP 3,350'],
        ['JOD', 249, 'JOD 48.100'],
    ]);
});
