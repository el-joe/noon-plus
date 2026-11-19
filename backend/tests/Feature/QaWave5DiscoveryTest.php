<?php

use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\SearchQueryStat;
use App\Domains\Classifieds\Models\Follow;
use App\Domains\Coupons\Enums\CouponScope;
use App\Domains\Coupons\Enums\CouponTarget;
use App\Domains\Coupons\Enums\CouponType;
use App\Domains\Coupons\Enums\CustomerSegment;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Coupons\Models\CouponRedemption;
use App\Domains\Customers\Models\RecentlyViewedProduct;
use App\Domains\FlashSales\Enums\FlashSaleEventStatus;
use App\Domains\FlashSales\Models\FlashSaleEvent;
use App\Domains\Listings\Models\Listing;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Reviews\Enums\ReviewStatus;
use App\Domains\Reviews\Models\ProductReview;
use App\Domains\Travel\Models\TravelBooking;
use App\Domains\Travel\Models\TravelDeparture;
use App\Domains\Travel\Models\TravelPackage;
use App\Domains\Travel\Support\TravelBookings;
use App\Domains\Travel\Support\TravelPackages;
use App\Domains\Vendors\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
| QA wave 5a — discovery and storefront APIs: seller storefront (QC-008),
| search analytics / suggest / trending, recently viewed, frequently bought
| together and compare (QC-043, G-A1), offers and coupon chips (QC-064,
| QC-057), PDP badges and model number (QC-057), PLP facets (QC-027) and
| trip detail data (QC-060).
*/

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
    Cache::flush();
    Storage::fake('local');
    Notification::fake();
});

/**
 * A sellable listing whose product has the given title (and optional attributes).
 *
 * @param  array<string, mixed>  $product
 */
function w5Listing(string $title, string $price = '100.00', ?Vendor $vendor = null, array $product = []): Listing
{
    $listing = sellableListing($price, 10, vendor: $vendor);
    $listing->variant->product->update(['title_en' => $title, 'title_ar' => $title] + $product);

    return $listing->refresh();
}

/**
 * A delivered order holding all these listings (one shipment).
 *
 * @param  array<int, Listing>  $listings
 */
function w5OrderWith(array $listings, SubOrderStatus $status = SubOrderStatus::Delivered): SubOrder
{
    $first = array_shift($listings);
    $subOrder = subOrderFor($first->vendor, $status, listing: $first);

    foreach ($listings as $listing) {
        $subOrder->items()->create([
            'listing_id' => $listing->id, 'sku' => $listing->variant->sku, 'title' => 'x', 'quantity' => 1,
            'unit_price' => $listing->price, 'line_total' => $listing->price,
        ]);
    }

    return $subOrder;
}

/**
 * @param  array<int, string>  $days  'Y-m-d' => hits
 */
function w5Searches(string $query, array $days, int $results = 5): void
{
    foreach ($days as $day => $hits) {
        SearchQueryStat::query()->create(['day' => $day, 'query' => $query, 'hits' => $hits, 'results_count' => $results]);
    }
}

describe('seller storefront (QC-008)', function () {
    it('shows an active store with its rating, policies and badges, and hides legal details', function () {
        $store = Vendor::factory()->create([
            'slug' => 'gadget-hub', 'store_name' => 'Gadget Hub', 'logo_path' => 'vendors/1/logo.png',
            'business_details' => ['store' => ['description' => 'Phones and more', 'cr_number' => 'CR-SECRET', 'tax_id' => 'TAX-SECRET'], 'address' => ['city' => 'Dubai', 'street' => 'Secret street 1']],
        ]);
        $listing = w5Listing('Nova phone', vendor: $store);
        $listing->update(['fulfilment' => 'fbn']);
        foreach ([5, 5, 4, 2] as $index => $rating) {
            ProductReview::query()->forceCreate(['product_id' => $listing->variant->product_id, 'vendor_id' => $store->id, 'customer_user_id' => 100 + $index, 'order_item_id' => subOrderFor($store, SubOrderStatus::Delivered, listing: $listing)->items()->value('id'), 'customer_name' => 'Buyer', 'rating' => $rating, 'status' => ReviewStatus::Published, 'body' => 'ok']);
        }

        $response = $this->getJson('/api/v1/uae-en/sellers/gadget-hub')->assertOk()
            ->assertJsonPath('data.name', 'Gadget Hub')
            ->assertJsonPath('data.description', 'Phones and more')
            ->assertJsonPath('data.city', 'Dubai')
            ->assertJsonPath('data.rating.count', 4)
            ->assertJsonPath('data.rating.average', '4.00')
            ->assertJsonPath('data.rating.positive_percent', 75)
            ->assertJsonPath('data.product_count', 1)
            ->assertJsonPath('data.followers', 0)
            ->assertJsonPath('data.following', false)
            ->assertJsonPath('data.policies.returns.window_days', 14)
            ->assertJsonPath('data.policies.shipping.free_over', ['amount' => '100.00', 'currency' => 'AED'])
            ->assertJsonPath('data.policies.shipping.fulfilled_by_nawy', true);

        expect(collect($response->json('data.badges'))->pluck('key')->all())->toContain('fulfilled_by_nawy', 'new_seller')
            ->and($response->getContent())->not->toContain('SECRET');

        Vendor::factory()->create(['slug' => 'held-store', 'status' => 'suspended']);
        Vendor::factory()->create(['slug' => 'souq-shop', 'type' => 'classified']);
        $this->getJson('/api/v1/uae-en/sellers/held-store')->assertNotFound();
        $this->getJson('/api/v1/uae-en/sellers/souq-shop')->assertNotFound();
        $this->getJson('/api/v1/omn-en/sellers/gadget-hub')->assertNotFound();
    });

    it('lists only the store\'s products with the catalogue filters, paging and facets', function () {
        $store = Vendor::factory()->create(['slug' => 'gadget-hub']);
        w5Listing('Nova phone', '300.00', $store);
        w5Listing('Nova case', '20.00', $store);
        w5Listing('Other phone', '250.00');

        $this->getJson('/api/v1/uae-en/sellers/gadget-hub/products?sort=price_asc')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('data.0.title', 'Nova case')->assertJsonPath('data.1.title', 'Nova phone');
        $this->getJson('/api/v1/uae-en/sellers/gadget-hub/products?q=phone&seller=someone-else')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.title', 'Nova phone');
        $this->getJson('/api/v1/uae-en/sellers/gadget-hub/products/facets')->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.sellers.0.slug', 'gadget-hub')
            ->assertJsonPath('data.price.min.amount', '20.00');
        $this->getJson('/api/v1/uae-en/sellers/gadget-hub/products?per_page=500')->assertUnprocessable();
    });

    it('lets a signed-in customer follow and unfollow a store and lists the stores they follow', function () {
        $store = Vendor::factory()->create(['slug' => 'gadget-hub', 'store_name' => 'Gadget Hub']);
        [, $token] = customerWithToken();

        $this->postJson('/api/v1/uae-en/sellers/gadget-hub/follow')->assertUnauthorized();
        $this->withToken($token)->postJson('/api/v1/uae-en/sellers/gadget-hub/follow')->assertOk()->assertJsonPath('data.following', true)->assertJsonPath('data.followers', 1);
        $this->withToken($token)->postJson('/api/v1/uae-en/sellers/gadget-hub/follow')->assertOk()->assertJsonPath('data.followers', 1);
        $this->withToken($token)->getJson('/api/v1/uae-en/sellers/gadget-hub')->assertJsonPath('data.following', true);
        $this->withToken($token)->getJson('/api/v1/uae-en/me/followed-sellers')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.slug', 'gadget-hub')->assertJsonPath('data.0.name', 'Gadget Hub');
        expect(Follow::query()->where('followable_type', 'shop')->where('followable_id', $store->id)->count())->toBe(1);

        $this->withToken($token)->deleteJson('/api/v1/uae-en/sellers/gadget-hub/follow')->assertOk()->assertJsonPath('data.following', false)->assertJsonPath('data.followers', 0);
        $this->withToken($token)->getJson('/api/v1/uae-en/me/followed-sellers')->assertJsonPath('meta.total', 0);
    });
});

describe('search analytics, popular, trending and suggest (QC-043, G-A1)', function () {
    it('counts each search by normalised query per day, with no personal data, and lists popular searches that found products', function () {
        w5Listing('Nova Phone X');

        foreach (['  Nova   PHONE ', 'nova phone', 'nova phone'] as $query) {
            $this->getJson('/api/v1/uae-en/products?q='.urlencode($query))->assertOk();
        }

        $this->getJson('/api/v1/uae-en/products?q=nova+phone&page=2')->assertOk();
        foreach (range(1, 3) as $ignored) {
            $this->getJson('/api/v1/uae-en/products?q=zzzz')->assertOk();
        }
        $this->getJson('/api/v1/uae-en/products?q=n')->assertOk();
        $this->getJson('/api/v1/uae-en/products?q=rare')->assertOk();

        $stat = SearchQueryStat::query()->where('query', 'nova phone')->sole();
        expect($stat->hits)->toBe(3)->and($stat->results_count)->toBe(1)
            ->and(Schema::connection($stat->getConnectionName())->getColumnListing('search_query_stats'))->toBe(['id', 'day', 'query', 'hits', 'results_count', 'created_at', 'updated_at'])
            ->and(SearchQueryStat::query()->where('query', 'n')->exists())->toBeFalse();

        // zzzz found nothing and rare is below the minimum: neither is popular.
        $this->getJson('/api/v1/uae-en/search/popular')->assertOk()->assertExactJson(['data' => [['query' => 'nova phone']], 'meta' => []]);
        $this->getJson('/api/v1/omn-en/search/popular')->assertOk()->assertJsonCount(0, 'data');
    });

    it('trends the queries that grew against the days before, and falls back to the busiest categories', function () {
        $phones = Category::factory()->create(['name_en' => 'Phones', 'slug' => 'phones']);
        $cases = Category::factory()->create(['name_en' => 'Cases', 'parent_id' => $phones->id]);
        $books = Category::factory()->create(['name_en' => 'Books', 'slug' => 'books']);
        foreach ([[$cases, 2], [$books, 1]] as [$category, $count]) {
            foreach (range(1, $count) as $index) {
                w5Listing("Item {$category->id}-{$index}", product: ['category_id' => $category->id]);
            }
        }

        $this->getJson('/api/v1/uae-en/search/trending')->assertOk()->assertJsonPath('meta.source', 'categories')
            ->assertJsonPath('data.0.type', 'category')->assertJsonPath('data.0.category', 'phones')->assertJsonPath('data.1.category', 'books');

        $today = now()->toDateString();
        w5Searches('abaya', [$today => 30, now()->subDays(1)->toDateString() => 30, now()->subDays(6)->toDateString() => 5]);
        w5Searches('iphone', [$today => 20, now()->subDays(5)->toDateString() => 140, now()->subDays(8)->toDateString() => 140]);
        w5Searches('eid gifts', [$today => 6]);

        $this->getJson('/api/v1/uae-en/search/trending')->assertOk()->assertJsonPath('meta.source', 'searches')
            ->assertJsonPath('data.0.query', 'abaya')->assertJsonPath('data.1.query', 'eid gifts')->assertJsonCount(2, 'data');
    });

    it('suggests queries, products, brands and categories, matching % and _ literally', function () {
        $brand = Brand::factory()->create(['name' => 'Nova', 'slug' => 'nova']);
        $category = Category::factory()->create(['name_en' => 'Nova accessories', 'slug' => 'nova-accessories']);
        w5Listing('Nova X12 phone', '899.00', product: ['brand_id' => $brand->id, 'category_id' => $category->id]);
        w5Listing('100% cotton shirt', '50.00');
        w5Searches('nova x12', [now()->toDateString() => 5]);

        $this->getJson('/api/v1/uae-en/search/suggest?q=nova')->assertOk()
            ->assertJsonPath('data.queries', ['nova x12'])
            ->assertJsonPath('data.products.0.title', 'Nova X12 phone')
            ->assertJsonPath('data.products.0.price', ['amount' => '899.00', 'currency' => 'AED'])
            ->assertJsonPath('data.brands.0.slug', 'nova')
            ->assertJsonPath('data.categories.0.slug', 'nova-accessories');

        $this->getJson('/api/v1/uae-en/search/suggest?q='.urlencode('0%'))->assertOk()->assertJsonCount(1, 'data.products')->assertJsonPath('data.products.0.title', '100% cotton shirt');
        $this->getJson('/api/v1/uae-en/search/suggest?q='.urlencode('%%'))->assertOk()->assertJsonCount(0, 'data.products');
        $this->getJson('/api/v1/uae-en/search/suggest?q=__')->assertOk()->assertJsonCount(0, 'data.products')->assertJsonCount(0, 'data.brands');
        $this->getJson('/api/v1/uae-en/search/suggest?q=n')->assertUnprocessable();
    });
});

describe('recently viewed sync (QC-043)', function () {
    it('merges the device history, keeps the newest view and the cap, and deletes one or all', function () {
        config(['nawy.discovery.recently_viewed_max' => 2]);
        [$customer, $token] = customerWithToken();
        $a = w5Listing('Product A')->variant->product;
        $b = w5Listing('Product B')->variant->product;
        $c = w5Listing('Product C')->variant->product;
        $c->update(['status' => 'draft']);

        $this->getJson('/api/v1/uae-en/me/recently-viewed')->assertUnauthorized();
        $this->withToken($token)->postJson('/api/v1/uae-en/me/recently-viewed', ['items' => [
            ['slug' => $a->slug, 'viewed_at' => now()->subHour()->toIso8601String()],
            ['slug' => $b->slug, 'viewed_at' => now()->subHours(2)->toIso8601String()],
            ['slug' => $c->slug],
            ['slug' => 'no-such-product'],
        ]])->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.slug', $a->slug)->assertJsonPath('data.1.slug', $b->slug);

        // An older view from another device does not move B up; a newer one does.
        $this->withToken($token)->postJson('/api/v1/uae-en/me/recently-viewed', ['items' => [['slug' => $b->slug, 'viewed_at' => now()->subDays(3)->toIso8601String()]]])
            ->assertJsonPath('data.0.slug', $a->slug);
        $this->withToken($token)->postJson('/api/v1/uae-en/me/recently-viewed', ['items' => [['slug' => $b->slug]]])
            ->assertJsonPath('data.0.slug', $b->slug)->assertJsonPath('data.0.in_stock', true);

        $d = w5Listing('Product D')->variant->product;
        $this->withToken($token)->postJson('/api/v1/uae-en/me/recently-viewed', ['items' => [['slug' => $d->slug]]])->assertJsonCount(2, 'data');
        expect(RecentlyViewedProduct::query()->where('customer_user_id', $customer->id)->pluck('product_id')->sort()->values()->all())->toBe([$b->id, $d->id]);

        $this->withToken($token)->deleteJson("/api/v1/uae-en/me/recently-viewed/{$d->slug}")->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($token)->deleteJson('/api/v1/uae-en/me/recently-viewed')->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($token)->postJson('/api/v1/uae-en/me/recently-viewed', ['items' => []])->assertUnprocessable();
    });
});

describe('frequently bought together and compare (QC-043, QC-057)', function () {
    it('ranks products bought in the same paid or delivered orders and prices the bundle', function () {
        config(['nawy.discovery.fbt_min_orders' => 2]);
        $phone = w5Listing('Phone', '500.00');
        $case = w5Listing('Case', '20.00');
        $charger = w5Listing('Charger', '50.00');
        $cable = w5Listing('Cable', '10.00');
        $old = w5Listing('Old item', '5.00');

        w5OrderWith([$phone, $case, $charger]);
        w5OrderWith([$phone, $case]);
        w5OrderWith([$phone, $charger], SubOrderStatus::Completed);
        w5OrderWith([$phone, $cable]);                                     // only one order: below the minimum
        w5OrderWith([$phone, $cable], SubOrderStatus::Cancelled);          // cancelled: not counted
        w5OrderWith([$phone, $cable], SubOrderStatus::Placed);             // COD not yet delivered: not counted
        foreach (range(1, 2) as $ignored) {
            w5OrderWith([$phone, $old])->order->forceFill(['placed_at' => now()->subDays(200)])->save();
        }

        $this->getJson("/api/v1/uae-en/products/{$phone->variant->product->slug}/frequently-bought-together")->assertOk()
            ->assertJsonPath('data.product.listing_id', $phone->id)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.title', 'Case')->assertJsonPath('data.items.0.listing_id', $case->id)->assertJsonPath('data.items.0.bought_together_orders', 2)
            ->assertJsonPath('data.items.1.title', 'Charger')
            ->assertJsonPath('data.bundle_total', ['amount' => '570.00', 'currency' => 'AED']);

        // An item that can no longer be bought drops out of the bundle.
        $charger->forceFill(['status' => 'paused'])->save();
        $this->getJson("/api/v1/uae-en/products/{$phone->variant->product->slug}/frequently-bought-together")
            ->assertJsonCount(1, 'data.items')->assertJsonPath('data.bundle_total.amount', '520.00');
        $this->getJson("/api/v1/uae-en/products/{$case->variant->product->slug}/frequently-bought-together")->assertJsonPath('data.items.0.title', 'Phone');
        $this->getJson("/api/v1/omn-en/products/{$phone->variant->product->slug}/frequently-bought-together")->assertNotFound();
    });

    it('compares 2 to 4 products side by side with aligned specification rows', function () {
        $a = w5Listing('Phone A', '500.00', product: ['model_number' => 'A-100', 'specifications' => ['Screen' => '6.1"', 'Battery' => '4000 mAh']]);
        $b = w5Listing('Phone B', '450.00', product: ['specifications' => [['label' => 'Screen', 'value' => '6.7"'], ['label' => 'Weight', 'value' => '190 g']]]);
        $a->variant->update(['option_values' => ['colour' => 'Black']]);

        $response = $this->getJson('/api/v1/uae-en/products/compare?slugs='.$a->variant->product->slug.','.$b->variant->product->slug)->assertOk()
            ->assertJsonPath('data.products.0.title', 'Phone A')->assertJsonPath('data.products.0.model_number', 'A-100')
            ->assertJsonPath('data.products.0.options', ['colour' => ['Black']])
            ->assertJsonPath('data.products.1.price.amount', '450.00')->assertJsonPath('data.products.1.listing_id', $b->id);

        expect($response->json('data.specifications'))->toBe([
            ['label' => 'Screen', 'values' => ['6.1"', '6.7"']],
            ['label' => 'Battery', 'values' => ['4000 mAh', null]],
            ['label' => 'Weight', 'values' => [null, '190 g']],
        ]);

        $this->getJson('/api/v1/uae-en/products/compare?slugs='.$a->variant->product->slug)->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['slugs']]]);
        $this->getJson('/api/v1/uae-en/products/compare?slugs=a,b,c,d,e')->assertUnprocessable();
    });
});

describe('offers for you and coupon chips (QC-064, QC-057)', function () {
    it('lists only public, usable coupons and the running flash sales; never private or one-use codes', function () {
        Coupon::factory()->create(['code' => 'PUBLIC10', 'is_public' => true, 'value' => '10', 'description' => '10% off everything']);
        Coupon::factory()->create(['code' => 'PRIVATE20', 'is_public' => false]);
        Coupon::factory()->create(['code' => 'ONEUSE', 'is_public' => true, 'usage_limit' => 1]);
        Coupon::factory()->create(['code' => 'EXPIRED', 'is_public' => true, 'ends_at' => now()->subDay()]);
        Coupon::factory()->create(['code' => 'OFF', 'is_public' => true, 'is_active' => false]);
        $usedUp = Coupon::factory()->create(['code' => 'USEDUP', 'is_public' => true, 'usage_limit' => 2]);
        $perCustomer = Coupon::factory()->create(['code' => 'ONCEEACH', 'is_public' => true, 'per_customer_limit' => 1, 'type' => CouponType::FixedAmount, 'value' => '25']);
        Coupon::factory()->create(['code' => 'WELCOME', 'is_public' => true, 'first_order_only' => true, 'customer_segment' => CustomerSegment::NewCustomers]);
        Coupon::factory()->create(['code' => 'LOYAL', 'is_public' => true, 'customer_segment' => CustomerSegment::ReturningCustomers]);
        Coupon::factory()->create(['code' => 'BOGO', 'is_public' => true, 'type' => CouponType::Bogo, 'value' => '100', 'buy_quantity' => 2, 'get_quantity' => 1]);
        [$customer, $token] = customerWithToken();
        foreach ([[$usedUp, 7], [$usedUp, 8], [$perCustomer, $customer->id]] as $index => [$coupon, $userId]) {
            CouponRedemption::query()->create(['coupon_id' => $coupon->id, 'order_id' => tap(subOrderFor(Vendor::factory()->create())->order)->update(['customer_user_id' => 999_999])->id, 'customer_user_id' => $userId, 'discount_amount' => '1', 'funded_by' => 'platform']);
        }
        FlashSaleEvent::factory()->create(['name' => 'Weekend deals', 'status' => FlashSaleEventStatus::Live, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);

        $guest = $this->getJson('/api/v1/uae-en/offers')->assertOk();
        expect(collect($guest->json('data.coupons'))->pluck('code')->sort()->values()->all())->toBe(['BOGO', 'ONCEEACH', 'PUBLIC10', 'WELCOME'])
            ->and(collect($guest->json('data.coupons'))->firstWhere('code', 'WELCOME')['requires_sign_in'])->toBeTrue()
            ->and(collect($guest->json('data.coupons'))->firstWhere('code', 'PUBLIC10')['label'])->toBe('10% off')
            ->and(collect($guest->json('data.coupons'))->firstWhere('code', 'ONCEEACH')['label'])->toBe('AED 25.00 off')
            ->and(collect($guest->json('data.coupons'))->firstWhere('code', 'BOGO')['label'])->toBe('Buy 2 get 1 free')
            ->and($guest->json('data.flash_sales.0.state'))->toBe('live');

        // Signed in, no order yet: the welcome coupon is theirs; the returning-customer one is not; ONCEEACH is spent.
        $signedIn = $this->withToken($token)->getJson('/api/v1/uae-en/offers')->assertOk();
        expect(collect($signedIn->json('data.coupons'))->pluck('code')->sort()->values()->all())->toBe(['BOGO', 'PUBLIC10', 'WELCOME'])
            ->and(collect($signedIn->json('data.coupons'))->firstWhere('code', 'WELCOME')['requires_sign_in'])->toBeFalse();

        $this->getJson('/api/v1/omn-en/offers')->assertOk()->assertJsonCount(0, 'data.coupons');
    });

    it('shows on a product page only the public coupons whose targets cover one of its offers', function () {
        $fashion = Category::factory()->create(['name_en' => 'Fashion']);
        $abayas = Category::factory()->create(['parent_id' => $fashion->id]);
        $store = Vendor::factory()->create(['slug' => 'abaya-house']);
        $abaya = w5Listing('Black abaya', '300.00', $store, ['category_id' => $abayas->id]);
        $phone = w5Listing('Phone', '900.00');

        Coupon::factory()->create(['code' => 'ALL5', 'is_public' => true, 'value' => '5']);
        Coupon::factory()->create(['code' => 'FASHION10', 'is_public' => true, 'target_type' => CouponTarget::Category, 'target_ids' => [$fashion->id]]);
        Coupon::factory()->create(['code' => 'STORE15', 'is_public' => true, 'scope' => CouponScope::Vendor, 'vendor_id' => $store->id, 'funded_by' => 'vendor', 'value' => '15']);
        Coupon::factory()->create(['code' => 'HIDDEN', 'is_public' => false]);

        $chips = $this->getJson("/api/v1/uae-en/products/{$abaya->variant->product->slug}/coupons")->assertOk();
        expect(collect($chips->json('data'))->pluck('code')->sort()->values()->all())->toBe(['ALL5', 'FASHION10', 'STORE15'])
            ->and(collect($chips->json('data'))->firstWhere('code', 'FASHION10')['applies_to'])->toBe(['type' => 'category', 'items' => [['slug' => $fashion->slug, 'name' => 'Fashion']]])
            ->and(collect($chips->json('data'))->firstWhere('code', 'STORE15')['seller'])->toBe(['slug' => 'abaya-house', 'name' => $store->store_name]);

        $this->getJson("/api/v1/uae-en/products/{$phone->variant->product->slug}/coupons")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'ALL5');
    });

    it('lets marketing mark a coupon public from the admin form', function () {
        $this->actingAs(adminAccount('uae', 'marketing'))->post(onPanel('admin', 'coupons'), [
            'code' => 'SHOWME', 'scope' => 'platform', 'funded_by' => 'platform', 'type' => 'percentage', 'value' => '10',
            'target_type' => 'all', 'customer_segment' => 'everyone', 'first_order_only' => 0, 'is_active' => 1, 'is_public' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        expect(Coupon::query()->where('code', 'SHOWME')->sole()->is_public)->toBeTrue();
    });
});

describe('PDP data and PLP facets (QC-057, QC-027)', function () {
    it('sends the model number and merchandising badges on the product page and cards', function () {
        config(['nawy.discovery.top_rated_min_reviews' => 2]);
        $listing = w5Listing('Nova X12', '900.00', product: ['model_number' => 'NX-12', 'rating_average' => '4.80', 'rating_count' => 5]);
        $listing->variant->product->forceFill(['bestseller_rank' => 3])->save();
        $listing->update(['sale_price' => '850.00', 'fulfilment' => 'fbn']);
        $old = w5Listing('Old phone');
        Product::query()->whereKey($old->variant->product_id)->update(['created_at' => now()->subDays(90)]);

        $pdp = $this->getJson("/api/v1/uae-en/products/{$listing->variant->product->slug}")->assertOk()->assertJsonPath('data.model_number', 'NX-12');
        expect(collect($pdp->json('data.badges'))->pluck('key')->all())->toBe(['bestseller', 'new', 'top_rated', 'deal', 'fulfilled_by_nawy'])
            ->and($pdp->json('data.badges.0.label'))->toBe('Best seller');

        $cards = collect($this->getJson('/api/v1/uae-en/products')->assertOk()->json('data'))->keyBy('title');
        expect(collect($cards['Nova X12']['badges'])->pluck('key')->all())->toBe(['bestseller', 'new', 'top_rated'])
            ->and($cards['Old phone']['badges'])->toBe([]);

        $product = $listing->variant->product;
        $this->actingAs(adminAccount('uae', 'operations'))->put(onPanel('admin', "catalog/products/{$product->id}"), [
            'title_en' => $product->title_en, 'title_ar' => $product->title_ar, 'slug' => $product->slug, 'category_id' => $product->category_id,
            'status' => 'active', 'fulfilment_mode' => 'ready_to_ship', 'model_number' => 'NX-12B',
        ])->assertRedirect()->assertSessionHasNoErrors();
        expect($product->refresh()->model_number)->toBe('NX-12B');
    });

    it('filters and counts the Fulfilled by, Condition and Deals rail groups', function () {
        $fbn = w5Listing('Warehouse phone', '500.00');
        $fbn->update(['fulfilment' => 'fbn', 'sale_price' => '450.00']);
        $used = w5Listing('Used phone', '300.00');
        $used->update(['condition' => 'refurbished']);
        w5Listing('Plain phone', '400.00');

        $this->getJson('/api/v1/uae-en/products/facets')->assertOk()
            ->assertJsonPath('data.fulfilment', [['value' => 'fbm', 'count' => 2], ['value' => 'fbn', 'count' => 1]])
            ->assertJsonPath('data.conditions', [['value' => 'new', 'count' => 2], ['value' => 'refurbished', 'count' => 1]])
            ->assertJsonPath('data.deals', 1);
        $this->getJson('/api/v1/uae-en/products?fulfilment=fbn')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.title', 'Warehouse phone');
        $this->getJson('/api/v1/uae-en/products?condition=refurbished')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.title', 'Used phone');
        $this->getJson('/api/v1/uae-en/products?deals=1')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.title', 'Warehouse phone');
        $this->getJson('/api/v1/uae-en/products/facets?fulfilment=fbn')->assertJsonPath('meta.total', 1)->assertJsonCount(2, 'data.fulfilment')->assertJsonPath('data.deals', 1);
        $this->getJson('/api/v1/uae-en/products?deals=maybe')->assertUnprocessable();
    });

    it('filters and counts the size and colour rail from variant options (existing attr facets)', function () {
        $red = w5Listing('Red dress');
        $red->variant->update(['option_values' => ['colour' => 'Red', 'size' => 'M']]);
        $blue = w5Listing('Blue dress');
        $blue->variant->update(['option_values' => ['colour' => 'Blue', 'size' => 'M']]);

        $this->getJson('/api/v1/uae-en/products/facets?attr[colour]=Red')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.attributes.0.key', 'colour')->assertJsonCount(2, 'data.attributes.0.values')
            ->assertJsonPath('data.attributes.1.key', 'size')->assertJsonPath('data.attributes.1.values.0', ['value' => 'M', 'count' => 2]);
    });
});

describe('trip detail (QC-060)', function () {
    /**
     * @return array{0: TravelPackage, 1: TravelDeparture}
     */
    function w5Trip(array $rooms): array
    {
        $agency = Vendor::factory()->create(['type' => 'travel', 'store_name' => 'Gulf Holidays']);
        $packages = app(TravelPackages::class);
        $package = $packages->create($agency, [
            'title_en' => 'Umrah 7 nights', 'title_ar' => 'عمرة', 'destination_city' => 'Makkah', 'nights' => 7, 'trip_type' => 'umrah',
            'itinerary' => [['day' => 1, 'title' => 'Arrival']], 'inclusions' => ['Hotel'],
        ], 1);
        $departure = $packages->addDeparture($package, now()->addDays(30)->toDateString(), now()->addDays(37)->toDateString(), 10, $rooms);
        $packages->attachContract($package, UploadedFile::fake()->create('contract.pdf', 50, 'application/pdf'));
        $packages->submit($package->refresh(), 1);
        $packages->approve($package->refresh(), 1);

        return [$package->refresh(), $departure->refresh()];
    }

    it('returns the gallery, booking stats and child price, and books adults and children', function () {
        [$package, $departure] = w5Trip(['double' => '2000.00', 'child' => '1200.00']);
        $package->forceFill(['cover_path' => 'travel/cover.jpg', 'gallery' => ['travel/a.jpg', 'travel/b.jpg']])->save();

        $this->getJson("/api/v1/uae-en/travel/packages/{$package->slug}")->assertOk()
            ->assertJsonCount(3, 'data.gallery')->assertJsonPath('data.gallery.1.alt', 'Umrah 7 nights')
            ->assertJsonPath('data.stats', ['rating' => null, 'travellers' => 0, 'bookings' => 0, 'agency_trips' => 1])
            ->assertJsonPath('data.departures.0.child_price_per_person', ['amount' => '1200.00', 'currency' => 'AED']);

        $booking = $this->postJson("/api/v1/uae-en/travel/departures/{$departure->id}/bookings", [
            'name' => 'Sara Ahmed', 'email' => 'sara@example.com', 'phone' => '0501234567',
            'rooms' => [['room_type' => 'double', 'travellers' => 2]], 'children' => 2,
        ])->assertCreated()->assertJsonPath('data.seats', 4)->assertJsonPath('data.adults', 2)->assertJsonPath('data.children', 2)
            ->assertJsonPath('data.quoted_total.amount', '6400.00');

        app(TravelBookings::class)->confirm(TravelBooking::query()->where('number', $booking->json('data.number'))->sole(), 1);
        $this->getJson("/api/v1/uae-en/travel/packages/{$package->slug}")->assertJsonPath('data.stats.travellers', 4)->assertJsonPath('data.stats.bookings', 1);

        [, $adultsOnly] = w5Trip(['double' => '2000.00']);
        $this->postJson("/api/v1/uae-en/travel/departures/{$adultsOnly->id}/bookings", [
            'name' => 'Sara Ahmed', 'email' => 'sara@example.com', 'phone' => '0501234567',
            'rooms' => [['room_type' => 'double', 'travellers' => 2]], 'children' => 1,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'children_not_offered');
    });
});
