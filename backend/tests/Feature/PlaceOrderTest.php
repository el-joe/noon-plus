<?php

use App\Domains\Coupons\Models\Coupon;
use App\Domains\Coupons\Models\CouponRedemption;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Orders\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->actAsCountry('uae');
});

/**
 * @return array<string, mixed>
 */
function deliveryAddress(): array
{
    return ['address' => ['name' => 'Sara', 'city' => 'Dubai', 'area' => 'Dubai Marina', 'street' => 'Marina Gate 2, Apt 1203']];
}

it('places one order split into one shipment per seller and takes the stock', function () {
    [$customer, $token] = customerWithToken();
    $earbuds = sellableListing('249.00', stock: 5);
    $abaya = sellableListing('289.00', stock: 2);
    Coupon::factory()->create(['code' => 'NAWY10']);

    $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $earbuds->id, 'quantity' => 2]);
    $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $abaya->id, 'quantity' => 1]);
    $this->withToken($token)->postJson('/api/v1/uae-en/cart/coupons', ['code' => 'NAWY10']);

    $response = $this->withToken($token)->postJson('/api/v1/uae-en/orders', ['payment_method' => 'cod'] + deliveryAddress());

    $response->assertCreated()
        ->assertJsonPath('data.status', 'placed')
        ->assertJsonCount(2, 'data.shipments')
        ->assertJsonPath('data.totals.subtotal.amount', '787.00')
        ->assertJsonPath('data.totals.discount.amount', '78.70')
        ->assertJsonPath('data.totals.total.amount', '718.30');

    expect($response->json('data.number'))->toMatch('/^NW-AE-\d{7}$/')
        ->and(app(InventoryLedger::class)->available($earbuds))->toBe(3)
        ->and($abaya->fresh()->status->value)->toBe('active')
        ->and(CouponRedemption::query()->where('customer_user_id', $customer->id)->count())->toBe(1);

    $this->withToken($token)->getJson('/api/v1/uae-en/cart')->assertJsonCount(0, 'data.shipments');
});

it('marks a listing out of stock when an order buys the last unit', function () {
    [, $token] = customerWithToken();
    $listing = sellableListing(stock: 1);

    $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1]);
    $this->withToken($token)->postJson('/api/v1/uae-en/orders', ['payment_method' => 'card'] + deliveryAddress())->assertCreated();

    expect($listing->fresh()->status->value)->toBe('out_of_stock');
});

it('refuses checkout with 409 when stock was sold elsewhere after the hold expired', function () {
    [, $token] = customerWithToken();
    $listing = sellableListing(stock: 1);
    $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1]);

    $this->travel(20)->minutes();
    app(InventoryLedger::class)->move($listing, -1, 'sale');

    $this->withToken($token)->postJson('/api/v1/uae-en/orders', ['payment_method' => 'cod'] + deliveryAddress())
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'insufficient_stock');
    expect(Order::query()->count())->toBe(0);
});

it('refuses checkout with 403 until the email is verified', function () {
    [, $token] = customerWithToken(verified: false);

    $this->withToken($token)->postJson('/api/v1/uae-en/orders', ['payment_method' => 'cod'] + deliveryAddress())
        ->assertForbidden()
        ->assertJsonPath('error.code', 'email_not_verified');
});

it('refuses an empty cart with 422 and requires an address', function () {
    [, $token] = customerWithToken();

    $this->withToken($token)->postJson('/api/v1/uae-en/orders', ['payment_method' => 'cod'] + deliveryAddress())
        ->assertStatus(422)->assertJsonPath('error.code', 'cart_empty');
    $this->withToken($token)->postJson('/api/v1/uae-en/orders', ['payment_method' => 'cod'])
        ->assertJsonValidationErrors(['address'], 'error.fields');
});

it('shows customers only their own orders', function () {
    [, $ownerToken] = customerWithToken();
    [, $otherToken] = customerWithToken();
    $listing = sellableListing();
    $this->withToken($ownerToken)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1]);
    $number = $this->withToken($ownerToken)->postJson('/api/v1/uae-en/orders', ['payment_method' => 'cod'] + deliveryAddress())->json('data.number');

    $this->withToken($ownerToken)->getJson("/api/v1/uae-en/orders/{$number}")->assertOk();
    app('auth')->forgetGuards();
    $this->withToken($otherToken)->getJson("/api/v1/uae-en/orders/{$number}")->assertNotFound();
    $this->withToken($otherToken)->getJson('/api/v1/uae-en/orders')->assertJsonCount(0, 'data');
});
