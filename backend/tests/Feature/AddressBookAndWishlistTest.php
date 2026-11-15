<?php

use App\Domains\Customers\Models\CustomerAddress;
use App\Domains\Customers\Models\WishlistItem;
use App\Domains\Orders\Models\Order;
use App\Domains\Shipping\Models\ShippingZone;
use App\Domains\Shipping\Models\ShippingZoneArea;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| G-W5 address book (per country, default address, phone per country,
| zone / area, optional pin, checkout by `address_id`) and G-W6 wishlist.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->actAsCountry('uae');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function addressForm(array $overrides = []): array
{
    return $overrides + ['label' => 'home', 'name' => 'Sara Ahmed', 'phone' => '050 123 4567', 'city' => 'Dubai', 'area' => 'Marina', 'street' => 'Gate 2', 'building' => 'Tower 5'];
}

it('saves addresses with the phone in E.164 and the shipping zone, and the first one becomes the default', function () {
    $zone = ShippingZone::query()->create(['name' => 'Dubai metro', 'is_active' => true]);
    ShippingZoneArea::query()->create(['shipping_zone_id' => $zone->id, 'city' => 'Dubai', 'area' => null]);
    [, $token] = customerWithToken();

    $this->withToken($token)->postJson('/api/v1/uae-en/addresses', addressForm(['latitude' => 25.08, 'longitude' => 55.14]))->assertCreated()
        ->assertJsonPath('data.phone', '+971501234567')
        ->assertJsonPath('data.is_default', true)
        ->assertJsonPath('data.zone', ['id' => $zone->id, 'name' => 'Dubai metro'])
        ->assertJsonPath('data.location', ['latitude' => 25.08, 'longitude' => 55.14]);
    $second = $this->withToken($token)->postJson('/api/v1/uae-en/addresses', addressForm(['label' => 'work', 'city' => 'Sharjah']))->assertCreated()
        ->assertJsonPath('data.is_default', false)->assertJsonPath('data.zone', null)->json('data.id');

    $this->withToken($token)->postJson("/api/v1/uae-en/addresses/{$second}/default")->assertOk()->assertJsonPath('data.is_default', true);
    $this->withToken($token)->getJson('/api/v1/uae-en/addresses')->assertOk()
        ->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $second)->assertJsonPath('data.1.is_default', false);
    $this->getJson('/api/v1/uae-en/delivery-areas')->assertOk()->assertJsonPath('data.0.city', 'Dubai')->assertJsonPath('data.0.zone.name', 'Dubai metro');
});

it('validates the phone for the market country and the pin as a pair', function () {
    [, $token] = customerWithToken();

    $this->withToken($token)->postJson('/api/v1/uae-en/addresses', addressForm(['phone' => '+968 9123 4567']))
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed')->assertJsonValidationErrors(['phone'], 'error.fields');
    $this->withToken($token)->postJson('/api/v1/uae-en/addresses', addressForm(['latitude' => 25.1]))->assertUnprocessable()->assertJsonValidationErrors(['longitude'], 'error.fields');
    $this->withToken($token)->postJson('/api/v1/uae-en/addresses', ['name' => 'Sara'])->assertUnprocessable()->assertJsonValidationErrors(['phone', 'city', 'area', 'street'], 'error.fields');

    $this->actAsCountry('omn');
    $this->withToken($token)->postJson('/api/v1/omn-en/addresses', addressForm(['phone' => '9123 4567', 'city' => 'Muscat']))->assertCreated()->assertJsonPath('data.phone', '+96891234567');
});

it('updates, deletes and promotes the next default; another customer cannot touch the address', function () {
    [, $token] = customerWithToken();
    $first = $this->withToken($token)->postJson('/api/v1/uae-en/addresses', addressForm())->json('data.id');
    $second = $this->withToken($token)->postJson('/api/v1/uae-en/addresses', addressForm(['street' => 'Gate 9']))->json('data.id');

    $this->withToken($token)->patchJson("/api/v1/uae-en/addresses/{$second}", ['street' => 'Gate 10', 'apartment' => '1203'])->assertOk()
        ->assertJsonPath('data.street', 'Gate 10')->assertJsonPath('data.name', 'Sara Ahmed')->assertJsonPath('data.phone', '+971501234567');

    app('auth')->forgetGuards();
    [, $otherToken] = customerWithToken();
    $this->withToken($otherToken)->getJson("/api/v1/uae-en/addresses/{$first}")->assertNotFound()->assertJsonPath('error.code', 'not_found');
    $this->withToken($otherToken)->deleteJson("/api/v1/uae-en/addresses/{$first}")->assertNotFound();

    app('auth')->forgetGuards();
    $this->withToken($token)->deleteJson("/api/v1/uae-en/addresses/{$first}")->assertOk();
    expect(CustomerAddress::query()->find($second)->is_default)->toBeTrue();
});

it('places an order to a saved address by address_id, and only to an own address', function () {
    [$customer, $token] = customerWithToken();
    $addressId = $this->withToken($token)->postJson('/api/v1/uae-en/addresses', addressForm())->json('data.id');
    $listing = sellableListing('100.00');
    $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1])->assertOk();

    $other = CustomerAddress::query()->create(addressForm(['customer_user_id' => $customer->id + 999, 'phone' => '+971501234567']));
    $this->withToken($token)->postJson('/api/v1/uae-en/orders', ['payment_method' => 'cod', 'address_id' => $other->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['address_id'], 'error.fields');
    $this->withToken($token)->postJson('/api/v1/uae-en/orders', ['payment_method' => 'cod', 'address_id' => $addressId, 'address' => addressForm()])
        ->assertUnprocessable()->assertJsonValidationErrors(['address'], 'error.fields');

    $this->withToken($token)->postJson('/api/v1/uae-en/orders', ['payment_method' => 'cod', 'address_id' => $addressId])->assertCreated()
        ->assertJsonPath('data.shipping_address.street', 'Gate 2')
        ->assertJsonPath('data.shipping_address.phone', '+971501234567')
        ->assertJsonPath('data.shipping_address.address_id', $addressId);
    expect(Order::query()->sole()->shipping_address['building'])->toBe('Tower 5');
});

it('adds, lists and removes wishlist products, idempotently and per customer', function () {
    $listing = sellableListing('75.00');
    $product = $listing->variant->product;
    [, $token] = customerWithToken();

    $this->withToken($token)->postJson('/api/v1/uae-en/wishlist', ['product' => $product->slug])->assertCreated()->assertJsonPath('data.saved', true);
    $this->withToken($token)->postJson('/api/v1/uae-en/wishlist', ['product' => $product->slug])->assertOk();
    $this->withToken($token)->postJson('/api/v1/uae-en/wishlist', ['product' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors(['product'], 'error.fields');

    $this->withToken($token)->getJson('/api/v1/uae-en/wishlist')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', $product->slug)
        ->assertJsonPath('data.0.price.amount', '75.00')
        ->assertJsonPath('data.0.in_stock', true);

    app('auth')->forgetGuards();
    [, $otherToken] = customerWithToken();
    $this->withToken($otherToken)->getJson('/api/v1/uae-en/wishlist')->assertJsonCount(0, 'data');
    $this->withToken($otherToken)->deleteJson("/api/v1/uae-en/wishlist/{$product->slug}")->assertOk();
    expect(WishlistItem::query()->count())->toBe(1);

    app('auth')->forgetGuards();
    $this->withToken($token)->deleteJson("/api/v1/uae-en/wishlist/{$product->slug}")->assertOk()->assertJsonPath('data.saved', false);
    expect(WishlistItem::query()->count())->toBe(0);
    app('auth')->forgetGuards();
    $this->withoutToken()->getJson('/api/v1/uae-en/wishlist')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
});
