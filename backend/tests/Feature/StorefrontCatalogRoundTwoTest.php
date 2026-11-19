<?php

use App\Domains\Campaigns\Models\MarketerConversion;
use App\Domains\Campaigns\Support\Invitations;
use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\CategoryMeasurementField;
use App\Domains\Catalog\Models\ProductMedia;
use App\Domains\Catalog\Models\SizeGuideTemplate;
use App\Domains\Content\Support\BlockResolver;
use App\Domains\FlashSales\Enums\FlashSaleEventStatus;
use App\Domains\FlashSales\Enums\FlashSaleSubmissionStatus;
use App\Domains\FlashSales\Models\FlashSaleEvent;
use App\Domains\FlashSales\Models\FlashSaleSubmission;
use App\Domains\Listings\Models\Listing;
use App\Domains\LiveMedia\Support\LiveStreams;
use App\Domains\Marketers\Support\WhatsAppMessenger;
use App\Domains\Vendors\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->country = $this->actAsCountry('uae');
    Cache::flush();
    Storage::fake('local');
});

/**
 * A sellable listing whose product has a stored image (round 2 tests).
 */
function listingWithImage(string $price = '120.00'): Listing
{
    $listing = sellableListing($price, 20, vendor: Vendor::factory()->create());
    ProductMedia::query()->create(['product_id' => $listing->variant->product_id, 'vendor_id' => $listing->vendor_id, 'path' => 'products/'.$listing->variant->product_id.'/front.jpg', 'sort_order' => 1]);

    return $listing->refresh();
}

/**
 * @param  array<string, mixed>  $card
 */
function expectProductCard(array $card, Listing $listing): void
{
    expect($card['listing_id'])->toBe($listing->id)
        ->and($card['slug'])->toBe($listing->variant->product->slug)
        ->and($card['image']['url'])->toStartWith('http')->toEndWith('/storage/products/'.$listing->variant->product_id.'/front.jpg')
        ->and($card['rating'])->toBe(['average' => null, 'count' => 0]);
}

describe('images on cards (G-W14, G-W26)', function () {
    it('sends full image URLs and ratings in page-builder product blocks', function () {
        $listing = listingWithImage();
        $zones = app(BlockResolver::class)->resolve(['main' => [['type' => 'product_grid', 'settings' => ['source' => 'manual', 'listing_ids' => [$listing->id]]]]]);

        expectProductCard($zones['main'][0]['data']['products'][0], $listing);
        expect($zones['main'][0]['data']['products'][0]['price'])->toBe(['amount' => '120.00', 'currency' => 'AED']);
    });

    it('sends images on flash-sale deals and finds an event by its slug', function () {
        $listing = listingWithImage('200.00');
        $event = FlashSaleEvent::factory()->running()->inStatus(FlashSaleEventStatus::Live)->create(['name' => 'White Friday', 'display_name' => null]);
        FlashSaleSubmission::query()->create(['flash_sale_event_id' => $event->id, 'listing_id' => $listing->id, 'vendor_id' => $listing->vendor_id, 'original_price' => '200.00', 'flash_price' => '150.00', 'quantity_cap' => 5])
            ->forceFill(['status' => FlashSaleSubmissionStatus::Live])->save();
        $slug = $event->refresh()->slug;
        expect($slug)->toBe('white-friday-'.$event->id);

        $deal = $this->getJson("/api/v1/uae-en/flash-sales/{$slug}")->assertOk()->assertJsonPath('data.slug', $slug)->json('data.deals.0');
        expect($deal['price'])->toBe(['amount' => '150.00', 'currency' => 'AED'])
            ->and($deal['product']['image']['url'])->toEndWith('/front.jpg')
            ->and($deal['product']['rating'])->toBe(['average' => null, 'count' => 0]);
        $this->getJson("/api/v1/uae-en/flash-sales/{$event->id}")->assertOk()->assertJsonPath('data.slug', $slug);
        $this->getJson('/api/v1/uae-en/flash-sales/no-such-sale')->assertNotFound();
    });

    it('sends images on live-stream products and an HLS URL only when configured', function () {
        $listing = listingWithImage();
        $streams = app(LiveStreams::class);
        $live = $streams->createLive(['title_en' => 'Live sale', 'title_ar' => 'مباشر'], 1);
        $streams->linkProducts($live, [$listing->id]);
        $streams->goLive($live, 1);

        // D18: a live broadcast plays over native WebRTC (no HLS egress any more).
        $response = $this->getJson("/api/v1/uae-en/live-streams/{$live->slug}")->assertOk()
            ->assertJsonPath('data.playback.webrtc', true)->assertJsonMissingPath('data.playback.hls_url');
        expectProductCard($response->json('data.products.0'), $listing);
    });

    it('sends creator avatar and cover URLs and product-card picks', function () {
        WhatsAppMessenger::fake();
        marketerDefaults(['influencer_fixed' => '200', 'affiliate_percent' => '10', 'broker_fixed' => '2', 'broker_percent' => '5', 'fee_percent' => '10', 'fee_fixed' => '0']);
        $listing = listingWithImage('105.00');
        [, $affiliate] = marketerAccount(['affiliate']);
        $affiliate->forceFill(['avatar_path' => 'creators/a.jpg', 'cover_path' => 'https://cdn.example.com/cover.jpg'])->save();
        $invitation = app(Invitations::class)->joinOpen(liveCampaign($listing->vendor, $listing), $affiliate);

        $response = $this->getJson("/api/v1/uae-en/creators/{$affiliate->slug}")->assertOk()
            ->assertJsonPath('data.avatar_url', fn (string $url): bool => str_starts_with($url, 'http') && str_ends_with($url, '/storage/creators/a.jpg'))
            ->assertJsonPath('data.cover_url', 'https://cdn.example.com/cover.jpg')
            ->assertJsonPath('data.picks.0.referral_code', $invitation->referral_code)
            ->assertJsonPath('data.picks.0.price', ['amount' => '105.00', 'currency' => 'AED']);
        expectProductCard($response->json('data.picks.0'), $listing);
    });
});

describe('referrals (G-W30) and checkout attribution', function () {
    it('lists the creator\'s other picks on the referral landing and credits a referral code sent at checkout', function () {
        WhatsAppMessenger::fake();
        marketerDefaults(['influencer_fixed' => '200', 'affiliate_percent' => '10', 'broker_fixed' => '2', 'broker_percent' => '5', 'fee_percent' => '10', 'fee_fixed' => '0']);
        $vendor = Vendor::factory()->create();
        $first = sellableListing('105.00', 50, vendor: $vendor);
        $second = listingWithImage('210.00');
        [, $affiliate] = marketerAccount(['affiliate']);
        $invitation = app(Invitations::class)->joinOpen(liveCampaign($vendor, $first), $affiliate);
        $other = app(Invitations::class)->joinOpen(liveCampaign($second->vendor, $second), $affiliate);

        $this->getJson("/api/v1/uae-en/referrals/{$invitation->referral_code}")->assertOk()
            ->assertJsonPath('data.destination.listing_id', $first->id)
            ->assertJsonCount(1, 'data.picks')
            ->assertJsonPath('data.picks.0.listing_id', $second->id)
            ->assertJsonPath('data.picks.0.referral_code', $other->referral_code);

        [, $token] = customerWithToken();
        $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $first->id, 'quantity' => 1])->assertOk();
        $this->withToken($token)->postJson('/api/v1/uae-en/orders', [
            'payment_method' => 'cod', 'referral_code' => strtolower($invitation->referral_code),
            'address' => ['name' => 'Sara', 'phone' => '+971500000000', 'city' => 'Dubai', 'area' => 'Marina', 'street' => 'Gate 2'],
        ])->assertCreated();

        expect(MarketerConversion::query()->sole())->invitation_id->toBe($invitation->id)->attribution->toBe('direct');
    });
});

describe('catalogue extras (G-W15, G-W16, G-W17, G-W28)', function () {
    it('gives cart lines the measurement fields of a made-to-order product', function () {
        $category = Category::factory()->create(['slug' => 'abayas']);
        CategoryMeasurementField::query()->create(['category_id' => $category->id, 'key' => 'height', 'label_en' => 'Height', 'label_ar' => 'الطول', 'unit' => 'cm', 'min_value' => 120, 'max_value' => 210]);
        $listing = sellableListing('450.00');
        $listing->variant->product->update(['category_id' => $category->id, 'fulfilment_mode' => 'made_to_order', 'lead_days' => 10]);
        [, $token] = customerWithToken();

        $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1])->assertOk()
            ->assertJsonPath('data.shipments.0.items.0.measurement_fields.0.key', 'height')
            ->assertJsonPath('data.shipments.0.items.0.measurements_required', true)
            ->assertJsonPath('data.shipments.0.items.0.measurements_missing', true);
        $this->withToken($token)->putJson("/api/v1/uae-en/cart/items/{$listing->id}/measurements", ['measurements' => ['height' => 165]])->assertOk();
        $this->withToken($token)->getJson('/api/v1/uae-en/cart')->assertOk()->assertJsonPath('data.shipments.0.items.0.measurements_missing', false);
    });

    it('serves brand landing data with categories, top products and the brand page', function () {
        $brand = Brand::factory()->create(['slug' => 'acme', 'name' => 'Acme', 'logo_path' => 'brands/acme.png']);
        $listing = listingWithImage();
        $listing->variant->product->update(['brand_id' => $brand->id]);

        $this->getJson('/api/v1/uae-en/brands/acme')->assertOk()
            ->assertJsonPath('data.name', 'Acme')
            ->assertJsonPath('data.logo', fn (string $url): bool => str_ends_with($url, '/storage/brands/acme.png'))
            ->assertJsonPath('data.product_count', 1)
            ->assertJsonPath('data.categories.0.id', $listing->variant->product->category_id)
            ->assertJsonPath('data.top_products.0.slug', $listing->variant->product->slug)
            ->assertJsonPath('data.page', null);
        $this->getJson('/api/v1/uae-en/brands/nope')->assertNotFound();
        $this->getJson('/api/v1/uae-en/categories/tree')->assertOk()->assertJsonPath('data.0.id', fn (int $id): bool => $id > 0);
    });

    it('describes size-guide columns', function () {
        $category = Category::factory()->create();
        $listing = sellableListing();
        $listing->variant->product->update(['category_id' => $category->id]);
        SizeGuideTemplate::query()->create(['category_id' => $category->id, 'name' => 'Alpha', 'type' => 'apparel_alpha', 'unit' => 'cm', 'columns' => ['size', 'chest_width'], 'rows' => [['size' => 'S', 'chest_width' => 88]]]);

        $this->getJson("/api/v1/uae-en/products/{$listing->variant->product->slug}/size-guide?unit=in")->assertOk()
            ->assertJsonPath('data.columns', ['size', 'chest_width'])
            ->assertJsonPath('data.column_defs', [
                ['key' => 'size', 'label' => 'Size', 'unit' => null, 'is_label' => true],
                ['key' => 'chest_width', 'label' => 'Chest Width', 'unit' => 'in', 'is_label' => false],
            ]);
    });
});
