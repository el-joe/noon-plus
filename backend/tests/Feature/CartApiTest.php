<?php

use App\Domains\Cart\Models\CartInventoryLock;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->actAsCountry('uae');
});

it('gives a guest a cart token and keeps the cart on the next request', function () {
    $listing = sellableListing('49.00');

    $response = $this->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 2])->assertOk();
    $token = $response->headers->get('X-Cart-Token');

    expect($token)->not->toBeEmpty();
    $this->withHeader('X-Cart-Token', $token)->getJson('/api/v1/uae-en/cart')
        ->assertJsonPath('data.shipments.0.items.0.quantity', 2)
        ->assertJsonPath('data.totals.subtotal.amount', '98.00');
});

it('holds stock for one cart so another cart cannot take the last units, until the hold expires', function () {
    $listing = sellableListing(stock: 3);

    $this->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 3])->assertOk();

    $this->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'insufficient_stock');

    $this->travel(16)->minutes();
    $this->artisan('nawy:cart:release-expired-locks')->assertSuccessful();

    expect(CartInventoryLock::query()->count())->toBe(0);
    $this->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1])->assertOk();
});

it('refuses items that are not on sale', function () {
    $listing = sellableListing(attributes: ['status' => 'paused']);

    $this->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'listing_unavailable');
});

it('merges a guest cart into the customer cart on sign-in, capped at available stock', function () {
    [$customer, $token] = customerWithToken();
    $listing = sellableListing(stock: 4);

    $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 2]);
    app('auth')->forgetGuards();
    $guestToken = $this->withoutToken()->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 2])
        ->headers->get('X-Cart-Token');

    $this->withToken($token)->withHeader('X-Cart-Token', $guestToken)->postJson('/api/v1/uae-en/cart/merge')
        ->assertOk()
        ->assertJsonPath('data.shipments.0.items.0.quantity', 4);
});

it('removes an item and releases its hold', function () {
    $listing = sellableListing();
    $token = $this->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1])->headers->get('X-Cart-Token');

    $this->withHeader('X-Cart-Token', $token)->deleteJson("/api/v1/uae-en/cart/items/{$listing->id}")
        ->assertOk()
        ->assertJsonCount(0, 'data.shipments');
    expect(CartInventoryLock::query()->count())->toBe(0);
});
