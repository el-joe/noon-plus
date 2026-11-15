<?php

use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductMedia;
use App\Domains\Catalog\Models\ProductVariant;
use App\Domains\Catalog\Support\ProductImages;
use App\Domains\Listings\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
| G-W1 vendor-managed product images in Seller Center (create and edit
| listing): public disk, order, EN/AR alt text, own images only; shown in
| the catalogue, cart and order responses.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    Storage::fake('public');
    [$this->seller, $this->vendor] = sellerAccount('uae');
    $this->actAsCountry('uae');
    $this->variant = ProductVariant::factory()->for(Product::factory()->state(['slug' => 'pulse-watch']))->create(['sku' => 'PSW-5-BLK']);
    $this->actingAs($this->seller);
});

it('uploads images with the new listing and shows them on the product page', function () {
    $this->post(onPanel('partner', 'products'), [
        'product_variant_id' => $this->variant->id, 'condition' => 'new', 'fulfilment' => 'fbm', 'price' => '899.00',
        'handling_days' => 1, 'stock' => 4, 'intent' => 'draft',
        'images' => [UploadedFile::fake()->image('front.jpg', 800, 600), UploadedFile::fake()->image('side.png')],
        'image_alt_en' => 'Pulse watch', 'image_alt_ar' => 'ساعة بلس',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $media = ProductMedia::query()->orderBy('sort_order')->get();
    expect($media)->toHaveCount(2)
        ->and($media[0]->vendor_id)->toBe($this->vendor->id)
        ->and($media[0]->product_variant_id)->toBeNull()
        ->and($media[0]->width)->toBe(800)
        ->and($media[0]->alt_ar)->toBe('ساعة بلس')
        ->and($media[0]->sort_order)->toBeLessThan($media[1]->sort_order);
    Storage::disk('public')->assertExists($media[0]->path);

    Listing::query()->sole()->forceFill(['status' => 'active'])->save();
    $this->getJson('/api/v1/uae-en/products/pulse-watch')->assertOk()
        ->assertJsonCount(2, 'data.images')
        ->assertJsonPath('data.images.0.url', Storage::disk('public')->url($media[0]->path));
});

it('rejects files that are not images', function () {
    $this->post(onPanel('partner', 'products'), [
        'product_variant_id' => $this->variant->id, 'condition' => 'new', 'fulfilment' => 'fbm', 'price' => '899.00',
        'handling_days' => 1, 'stock' => 0, 'intent' => 'draft',
        'images' => [UploadedFile::fake()->create('specs.pdf', 20, 'application/pdf')],
    ])->assertSessionHasErrors('images.0');
    expect(Listing::query()->count())->toBe(0);
});

it('adds variant images from the edit page, edits alt text and order, and removes them', function () {
    $listing = sellableListing('50.00', vendor: $this->vendor, attributes: ['product_variant_id' => $this->variant->id]);

    $this->get(onPanel('partner', "products/{$listing->id}/edit"))->assertOk()->assertSee('Product images');
    $this->post(onPanel('partner', "products/{$listing->id}/images"), ['images' => [UploadedFile::fake()->image('black.jpg')], 'alt_en' => 'Black strap', 'variant_only' => '1'])
        ->assertSessionHasNoErrors();
    $media = ProductMedia::query()->sole();
    expect($media->product_variant_id)->toBe($this->variant->id);

    $this->put(onPanel('partner', "products/{$listing->id}/images/{$media->id}"), ['alt_en' => 'Black', 'alt_ar' => 'أسود', 'sort_order' => 7])->assertSessionHasNoErrors();
    expect($media->fresh())->alt_ar->toBe('أسود')->sort_order->toBe(7);

    $this->getJson('/api/v1/uae-en/products/pulse-watch')->assertJsonPath('data.images.0.variant_sku', 'PSW-5-BLK');
    $this->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1])->assertOk()
        ->assertJsonPath('data.shipments.0.items.0.image.alt', 'Black')
        ->assertJsonPath('data.shipments.0.items.0.product_slug', 'pulse-watch');

    $this->delete(onPanel('partner', "products/{$listing->id}/images/{$media->id}"))->assertSessionHasNoErrors();
    expect(ProductMedia::query()->count())->toBe(0);
    Storage::disk('public')->assertMissing($media->path);
});

it('lets a store manage only its own images and caps them per product', function () {
    [, $otherVendor] = sellerAccount('uae');
    $this->actAsCountry('uae');
    $otherListing = sellableListing('55.00', vendor: $otherVendor, attributes: ['product_variant_id' => $this->variant->id, 'condition' => 'used']);
    $theirs = ProductMedia::query()->create(['product_id' => $this->variant->product_id, 'vendor_id' => $otherVendor->id, 'path' => 'products/x/theirs.jpg']);
    $listing = sellableListing('50.00', vendor: $this->vendor, attributes: ['product_variant_id' => $this->variant->id]);

    $this->put(onPanel('partner', "products/{$listing->id}/images/{$theirs->id}"), ['sort_order' => 1])->assertNotFound();
    $this->delete(onPanel('partner', "products/{$listing->id}/images/{$theirs->id}"))->assertNotFound();
    $this->post(onPanel('partner', "products/{$otherListing->id}/images"), ['images' => [UploadedFile::fake()->image('a.jpg')]])->assertNotFound();

    $files = array_map(fn (int $i): UploadedFile => UploadedFile::fake()->image("{$i}.jpg"), range(1, ProductImages::MAX_PER_VENDOR_PRODUCT));
    $this->post(onPanel('partner', "products/{$listing->id}/images"), ['images' => $files])->assertSessionHasNoErrors();
    $this->post(onPanel('partner', "products/{$listing->id}/images"), ['images' => [UploadedFile::fake()->image('one-more.jpg')]])->assertSessionHasErrors('images');
    expect(ProductMedia::query()->where('vendor_id', $this->vendor->id)->count())->toBe(ProductImages::MAX_PER_VENDOR_PRODUCT);
});

it('shows line images on the customer order', function () {
    $listing = sellableListing('40.00', stock: 5, attributes: ['product_variant_id' => $this->variant->id]);
    ProductMedia::query()->create(['product_id' => $this->variant->product_id, 'path' => 'products/x/front.jpg', 'alt_en' => 'Front']);
    [$order, , $token] = placeOrderVia('cod', vendor: $listing->vendor);
    $order->subOrders()->first()->items()->update(['listing_id' => $listing->id]);

    $this->withToken($token)->getJson("/api/v1/uae-en/orders/{$order->number}")->assertOk()
        ->assertJsonPath('data.shipments.0.items.0.image.alt', 'Front')
        ->assertJsonPath('data.shipments.0.items.0.product_slug', 'pulse-watch');
});
