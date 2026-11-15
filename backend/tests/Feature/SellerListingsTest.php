<?php

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductVariant;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Orders\Enums\SubOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    [$this->seller, $this->vendor] = sellerAccount('uae');
    $this->actAsCountry('uae');
    $this->variant = ProductVariant::factory()->for(Product::factory()->state(['title_en' => 'Pulse Smart Watch']))->create(['sku' => 'PSW-5-BLK']);
    $this->actingAs($this->seller);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function listingForm(int $variantId, array $overrides = []): array
{
    return $overrides + [
        'product_variant_id' => $variantId,
        'condition' => 'new',
        'fulfilment' => 'fbm',
        'price' => '899.00',
        'sale_price' => '849.00',
        'handling_days' => 1,
        'stock' => 4,
        'vendor_sku' => 'MY-PSW',
        'intent' => 'draft',
    ];
}

it('lists the store listings with search, status and stock filters', function () {
    $watch = sellableListing('899.00', stock: 3, vendor: $this->vendor, attributes: ['product_variant_id' => $this->variant->id]);
    $soldOut = sellableListing('20.00', stock: 0, vendor: $this->vendor);
    $low = sellableListing('30.00', stock: 2, vendor: $this->vendor);
    $draft = Listing::factory()->for($this->vendor)->create();

    $this->get(onPanel('partner', 'products?q=Pulse'))->assertOk()
        ->assertSee('data-listing="'.$watch->id.'"', false)
        ->assertDontSee('data-listing="'.$low->id.'"', false);
    $this->get(onPanel('partner', 'products?status=draft'))
        ->assertSee('data-listing="'.$draft->id.'"', false)
        ->assertDontSee('data-listing="'.$watch->id.'"', false);
    $this->get(onPanel('partner', 'products?stock=out'))
        ->assertSee('data-listing="'.$soldOut->id.'"', false)
        ->assertSee('data-listing="'.$draft->id.'"', false)
        ->assertDontSee('data-listing="'.$watch->id.'"', false);
    $this->get(onPanel('partner', 'products?stock=low'))
        ->assertSee('data-listing="'.$watch->id.'"', false)
        ->assertSee('data-listing="'.$low->id.'"', false)
        ->assertDontSee('data-listing="'.$soldOut->id.'"', false);
});

it('finds catalogue products to list and shows the gate checklist', function () {
    $this->get(onPanel('partner', 'products/create?q=Pulse'))->assertOk()->assertSee('Pulse Smart Watch')->assertSee('PSW-5-BLK');
    $this->get(onPanel('partner', 'products/create?variant='.$this->variant->id))
        ->assertOk()
        ->assertSee('Submit for review')
        ->assertSee('Seller Agreement accepted');
});

it('creates a draft listing with its opening stock in the ledger', function () {
    $this->post(onPanel('partner', 'products'), listingForm($this->variant->id))->assertRedirect();

    $listing = Listing::query()->where('vendor_id', $this->vendor->id)->sole();
    expect($listing->status)->toBe(ListingStatus::Draft)
        ->and((string) $listing->price)->toBe('899.0000')
        ->and((string) $listing->sale_price)->toBe('849.0000')
        ->and(app(InventoryLedger::class)->available($listing))->toBe(4)
        ->and($listing->movements()->sole()->reason)->toBe('initial_stock')
        ->and($listing->movements()->sole()->actor_user_id)->toBe($this->seller->id)
        ->and($listing->statusHistory()->sole()->to_status)->toBe(ListingStatus::Draft);
});

it('submits a new listing for review through the state machine', function () {
    $this->post(onPanel('partner', 'products'), listingForm($this->variant->id, ['intent' => 'submit']))->assertRedirect();

    $listing = Listing::query()->where('vendor_id', $this->vendor->id)->sole();
    expect($listing->status)->toBe(ListingStatus::PendingReview)
        ->and($listing->statusHistory()->latest('id')->first()->actor_user_id)->toBe($this->seller->id);
});

it('validates the offer', function (array $overrides, string $field) {
    $this->post(onPanel('partner', 'products'), listingForm($this->variant->id, $overrides))->assertSessionHasErrors($field);

    expect(Listing::query()->count())->toBe(0);
})->with([
    'sale price not below price' => [['sale_price' => '899.00'], 'sale_price'],
    'no price' => [['price' => ''], 'price'],
    'zero price' => [['price' => '0'], 'price'],
    'too many decimals for AED' => [['price' => '10.125'], 'price'],
    'unknown condition' => [['condition' => 'broken'], 'condition'],
    'unknown fulfilment' => [['fulfilment' => 'drone'], 'fulfilment'],
    'unknown variant' => [['product_variant_id' => 999999], 'product_variant_id'],
    'negative stock' => [['stock' => -1], 'stock'],
]);

it('accepts three decimals in a three-decimal currency', function () {
    [$omanSeller, $omanStore] = sellerAccount('omn');
    $this->actAsCountry('omn');
    $variant = ProductVariant::factory()->create();

    $this->actingAs($omanSeller)->post(onPanel('partner', 'products'), listingForm($variant->id, ['price' => '12.345', 'sale_price' => null]))->assertSessionHasNoErrors();

    expect((string) Listing::query()->where('vendor_id', $omanStore->id)->sole()->price)->toBe('12.3450');
});

it('refuses a second listing of the same variant and condition', function () {
    Listing::factory()->for($this->vendor)->create(['product_variant_id' => $this->variant->id, 'condition' => 'new']);

    $this->post(onPanel('partner', 'products'), listingForm($this->variant->id))->assertSessionHasErrors('product_variant_id');
    $this->post(onPanel('partner', 'products'), listingForm($this->variant->id, ['condition' => 'refurbished']))->assertSessionHasNoErrors();
});

it('blocks listing until the gates are cleared', function (Closure $arrange, string $message) {
    $arrange($this);

    $this->post(onPanel('partner', 'products'), listingForm($this->variant->id))->assertSessionHasErrors(['action' => $message]);

    expect(Listing::query()->count())->toBe(0);
})->with([
    'store not active' => [fn ($test) => $test->vendor->update(['status' => 'pending']), 'Your store is not active, so you cannot list products yet.'],
    'agreement not accepted' => [fn ($test) => $test->vendor->update(['seller_agreement_accepted_at' => null]), 'Accept the Seller Agreement before listing products.'],
    'parent category needs a contract' => [function ($test) {
        $parent = Category::factory()->create(['requires_contract' => true]);
        $test->variant->product->category->update(['parent_id' => $parent->id]);
    }, 'This category needs a signed category contract before you can list in it.'],
    'category needs a certification' => [fn ($test) => $test->variant->product->category->update(['requires_certification' => true]), 'This category needs an approved product certification before you can list in it.'],
]);

it('marks gated categories on the create checklist', function () {
    $this->variant->product->category->update(['requires_contract' => true]);

    $this->get(onPanel('partner', 'products/create?variant='.$this->variant->id))
        ->assertOk()
        ->assertSeeInOrder(['data-gate="category_contract"', 'Missing'], false);
});

it('also gates resubmitting a draft', function () {
    $listing = Listing::factory()->for($this->vendor)->create(['product_variant_id' => $this->variant->id]);
    $this->vendor->update(['seller_agreement_accepted_at' => null]);

    $this->post(onPanel('partner', "products/{$listing->id}/status"), ['action' => 'submit'])->assertSessionHasErrors('action');
    expect($listing->fresh()->status)->toBe(ListingStatus::Draft);

    $this->vendor->update(['seller_agreement_accepted_at' => now()]);
    $this->post(onPanel('partner', "products/{$listing->id}/status"), ['action' => 'submit'])->assertSessionHasNoErrors();
    expect($listing->fresh()->status)->toBe(ListingStatus::PendingReview);
});

it('updates price and handling of an own listing', function () {
    $listing = sellableListing('100.00', vendor: $this->vendor);

    $this->get(onPanel('partner', "products/{$listing->id}/edit"))->assertOk()->assertSee('Price &amp; handling', false);
    $this->put(onPanel('partner', "products/{$listing->id}"), ['price' => '120.50', 'sale_price' => '110', 'handling_days' => 3])->assertSessionHasNoErrors();

    expect($listing->fresh())
        ->price->toEqual('120.5000')
        ->sale_price->toEqual('110.0000')
        ->handling_days->toBe(3);
});

it('refuses changes to an archived listing', function () {
    $listing = Listing::factory()->for($this->vendor)->create(['status' => ListingStatus::Archived, 'price' => '10.00']);

    $this->put(onPanel('partner', "products/{$listing->id}"), ['price' => '20', 'handling_days' => 1])->assertSessionHasErrors('action');
    $this->post(onPanel('partner', "products/{$listing->id}/stock"), ['quantity' => 5, 'reason' => 'restock'])->assertSessionHasErrors('action');

    expect((string) $listing->fresh()->price)->toBe('10.0000');
});

it('moves stock through the ledger with a reason and restocks a sold-out listing', function () {
    $listing = sellableListing('100.00', stock: 1, vendor: $this->vendor);
    app(InventoryLedger::class)->move($listing, -1, 'order_placed');
    expect($listing->fresh()->status)->toBe(ListingStatus::OutOfStock);

    $this->post(onPanel('partner', "products/{$listing->id}/stock"), ['quantity' => 6, 'reason' => 'restock'])->assertSessionHasNoErrors();
    $this->post(onPanel('partner', "products/{$listing->id}/stock"), ['quantity' => -2, 'reason' => 'damaged'])->assertSessionHasNoErrors();

    $latest = $listing->movements()->latest('id')->first();
    expect(app(InventoryLedger::class)->available($listing))->toBe(4)
        ->and($listing->fresh()->status)->toBe(ListingStatus::Active)
        ->and($latest->reason)->toBe('damaged')
        ->and($latest->actor_user_id)->toBe($this->seller->id);
});

it('validates stock changes', function (array $payload, string $field) {
    $listing = sellableListing('100.00', stock: 2, vendor: $this->vendor);

    $this->post(onPanel('partner', "products/{$listing->id}/stock"), $payload)->assertSessionHasErrors($field);

    expect(app(InventoryLedger::class)->available($listing))->toBe(2);
})->with([
    'more than in stock' => [['quantity' => -3, 'reason' => 'lost'], 'quantity'],
    'zero' => [['quantity' => 0, 'reason' => 'restock'], 'quantity'],
    'no reason' => [['quantity' => 5], 'reason'],
    'unknown reason' => [['quantity' => 5, 'reason' => 'gift'], 'reason'],
]);

it('pauses, resumes and archives a listing', function () {
    $listing = sellableListing('100.00', stock: 2, vendor: $this->vendor);

    $this->post(onPanel('partner', "products/{$listing->id}/status"), ['action' => 'pause']);
    expect($listing->fresh()->status)->toBe(ListingStatus::Paused);

    $this->post(onPanel('partner', "products/{$listing->id}/status"), ['action' => 'resume']);
    expect($listing->fresh()->status)->toBe(ListingStatus::Active);

    $this->post(onPanel('partner', "products/{$listing->id}/status"), ['action' => 'archive']);
    expect($listing->fresh()->status)->toBe(ListingStatus::Archived)
        ->and($listing->statusHistory()->pluck('actor_user_id')->unique()->all())->toBe([$this->seller->id]);
});

it('does not resume a paused listing without stock', function () {
    $listing = Listing::factory()->for($this->vendor)->create(['status' => ListingStatus::Paused]);

    $this->post(onPanel('partner', "products/{$listing->id}/status"), ['action' => 'resume'])
        ->assertSessionHasErrors(['action' => 'Add stock before resuming this listing.']);

    expect($listing->fresh()->status)->toBe(ListingStatus::Paused);
});

it('does not archive a listing with open orders', function () {
    $listing = sellableListing('100.00', stock: 3, vendor: $this->vendor);
    $subOrder = subOrderFor($this->vendor, listing: $listing);

    $this->post(onPanel('partner', "products/{$listing->id}/status"), ['action' => 'archive'])->assertSessionHasErrors('action');
    expect($listing->fresh()->status)->toBe(ListingStatus::Active);

    $subOrder->forceFill(['status' => SubOrderStatus::Delivered])->save();
    $this->post(onPanel('partner', "products/{$listing->id}/status"), ['action' => 'archive'])->assertSessionHasNoErrors();
    expect($listing->fresh()->status)->toBe(ListingStatus::Archived);
});

it('refuses a status change the lifecycle does not allow', function () {
    $listing = Listing::factory()->for($this->vendor)->create(['status' => ListingStatus::PendingReview]);

    $this->post(onPanel('partner', "products/{$listing->id}/status"), ['action' => 'pause'])->assertSessionHasErrors('action');

    expect($listing->fresh()->status)->toBe(ListingStatus::PendingReview);
});
