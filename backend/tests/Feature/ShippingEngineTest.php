<?php

use App\Domains\Catalog\Models\ProductVariant;
use App\Domains\Listings\Models\Listing;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Settings\SettingsService;
use App\Domains\Shipping\Enums\ShippingMethod;
use App\Domains\Shipping\Enums\SurchargeType;
use App\Domains\Shipping\Models\ShippingRate;
use App\Domains\Shipping\Models\ShippingSurcharge;
use App\Domains\Shipping\Models\ShippingZone;
use App\Domains\Vendors\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->actAsCountry('uae');
    $this->travelTo('2026-11-16 08:00:00');
});

/**
 * A sellable listing whose variant has the given weight and dimensions.
 *
 * @param  array{0: string, 1: string, 2: string}|null  $dimensionsCm
 */
function parcelListing(string $price, int $weightGrams, ?array $dimensionsCm = null, array $attributes = [], ?Vendor $vendor = null): Listing
{
    $listing = sellableListing($price, attributes: $attributes, vendor: $vendor);
    $listing->variant->forceFill([
        'weight_grams' => $weightGrams,
        'length_cm' => $dimensionsCm[0] ?? null,
        'width_cm' => $dimensionsCm[1] ?? null,
        'height_cm' => $dimensionsCm[2] ?? null,
    ])->save();

    return $listing;
}

/**
 * Puts a listing in a new guest cart shipping to a city/area and returns the cart JSON.
 *
 * @return array<string, mixed>
 */
function quoteTo(Listing $listing, string $city, ?string $area = null, string $method = 'standard', int $quantity = 1, string $market = 'uae-en'): array
{
    $token = test()->flushHeaders()->putJson("/api/v1/{$market}/cart/items", ['listing_id' => $listing->id, 'quantity' => $quantity])->assertOk()->headers->get('X-Cart-Token');

    return test()->withHeader('X-Cart-Token', $token)
        ->putJson("/api/v1/{$market}/cart/shipping", ['city' => $city, 'area' => $area, 'method' => $method])
        ->assertOk()
        ->json('data');
}

it('charges the base fee plus every started kg over the included weight', function (int $grams, string $expectedFee) {
    ShippingRate::factory()->for(ShippingZone::factory()->covering(['Dubai']), 'zone')->create(['base_fee' => '15.00', 'base_weight_kg' => '1', 'per_kg_fee' => '2.00']);

    $cart = quoteTo(parcelListing('40.00', $grams), 'Dubai');

    expect($cart['shipments'][0]['shipping']['amount'])->toBe($expectedFee)
        ->and($cart['totals']['total']['amount'])->toBe(bcadd('40.00', $expectedFee, 2));
})->with([
    'within the base weight' => [1000, '15.00'],
    '1 g over starts a kg' => [1001, '17.00'],
    '2.3 kg' => [2300, '19.00'],
]);

it('bills the volumetric weight when the parcel is bulkier than it is heavy', function () {
    ShippingRate::factory()->for(ShippingZone::factory()->covering(['Dubai']), 'zone')->create(['base_fee' => '15.00', 'base_weight_kg' => '1', 'per_kg_fee' => '2.00']);
    $pillow = parcelListing('40.00', 800, ['40', '30', '20']);

    expect(quoteTo($pillow, 'Dubai')['shipments'][0]['shipping']['amount'])->toBe('23.00');
});

it('reads the volumetric divisor from the country setting', function () {
    app(SettingsService::class)->set('shipping.volumetric_divisor', 6000, $this->actAsCountry('uae'));
    ShippingRate::factory()->for(ShippingZone::factory()->covering(['Dubai']), 'zone')->create(['base_fee' => '15.00', 'base_weight_kg' => '1', 'per_kg_fee' => '2.00']);

    expect(quoteTo(parcelListing('40.00', 800, ['40', '30', '20']), 'Dubai')['shipments'][0]['shipping']['amount'])->toBe('21.00');
});

it('uses the weight slab the parcel fits in and the per-kg fee above the last slab', function (int $grams, string $expectedFee) {
    $rate = ShippingRate::factory()->for(ShippingZone::factory()->covering(['Dubai']), 'zone')->create(['base_fee' => '99.00', 'per_kg_fee' => '2.00']);
    $rate->slabs()->createMany([['max_kg' => '1', 'fee' => '10.00'], ['max_kg' => '5', 'fee' => '18.00']]);

    expect(quoteTo(parcelListing('40.00', $grams), 'Dubai')['shipments'][0]['shipping']['amount'])->toBe($expectedFee);
})->with([
    'first slab' => [900, '10.00'],
    'second slab' => [2300, '18.00'],
    'exactly the last slab limit' => [5000, '18.00'],
    '2.2 kg above the last slab' => [7200, '24.00'],
]);

it('adds percentage and fixed surcharges, fulfilment-specific ones only when they apply', function () {
    $zone = ShippingZone::factory()->covering(['Dubai'])->create();
    ShippingRate::factory()->for($zone, 'zone')->create(['base_fee' => '15.00', 'base_weight_kg' => '1', 'per_kg_fee' => '2.00']);
    ShippingSurcharge::query()->create(['name' => 'Remote area', 'shipping_zone_id' => $zone->id, 'type' => SurchargeType::Percent, 'value' => '10']);
    ShippingSurcharge::query()->create(['name' => 'FBN outbound', 'fulfilment' => 'fbn', 'type' => SurchargeType::Fixed, 'value' => '5.00']);
    ShippingSurcharge::query()->create(['name' => 'Express only', 'method' => ShippingMethod::Express, 'type' => SurchargeType::Fixed, 'value' => '50.00']);

    expect(quoteTo(parcelListing('40.00', 2300), 'Dubai')['shipments'][0]['shipping']['amount'])->toBe('20.90');

    expect(quoteTo(parcelListing('40.00', 2300, attributes: ['fulfilment' => 'fbn']), 'Dubai')['shipments'][0]['shipping']['amount'])->toBe('25.90');
});

it('prefers an area zone over a whole-city zone, then the default zone', function () {
    ShippingRate::factory()->for(ShippingZone::factory()->covering(['Dubai']), 'zone')->create(['base_fee' => '12.00', 'per_kg_fee' => '0']);
    ShippingRate::factory()->for(ShippingZone::factory()->covering([['Dubai', 'Dubai Marina']]), 'zone')->create(['base_fee' => '9.00', 'per_kg_fee' => '0']);
    ShippingRate::factory()->for(ShippingZone::factory()->asDefault(), 'zone')->create(['base_fee' => '25.00', 'per_kg_fee' => '0']);
    $listing = parcelListing('40.00', 500);

    expect(quoteTo($listing, 'Dubai', 'Dubai Marina')['shipments'][0]['shipping']['amount'])->toBe('9.00')
        ->and(quoteTo($listing, 'Dubai', 'Deira')['shipments'][0]['shipping']['amount'])->toBe('12.00')
        ->and(quoteTo($listing, 'Fujairah')['shipments'][0]['shipping']['amount'])->toBe('25.00');
});

it('falls back to the flat per-seller fee when no zone or rate matches', function () {
    ShippingRate::factory()->for(ShippingZone::factory()->covering(['Dubai']), 'zone')->create(['base_fee' => '12.00']);
    ShippingZone::factory()->covering(['Sharjah'])->create();

    $listing = parcelListing('40.00', 500);

    expect(quoteTo($listing, 'Abu Dhabi')['shipments'][0])
        ->shipping->amount->toBe('10.00')
        ->method->toBe('standard')
        ->shipping_options->toBe([])
        ->and(quoteTo($listing, 'Sharjah')['shipments'][0]['shipping']['amount'])->toBe('10.00');
});

it('lets the customer pick express, offers same-day only for items that leave today, and falls back to standard', function () {
    $zone = ShippingZone::factory()->covering(['Dubai'])->create();
    ShippingRate::factory()->for($zone, 'zone')->create(['base_fee' => '15.00', 'per_kg_fee' => '0']);
    ShippingRate::factory()->for($zone, 'zone')->method(ShippingMethod::Express)->create(['base_fee' => '25.00', 'per_kg_fee' => '0', 'transit_min_days' => 0, 'transit_max_days' => 1]);
    ShippingRate::factory()->for($zone, 'zone')->method(ShippingMethod::SameDay)->create(['base_fee' => '35.00', 'per_kg_fee' => '0', 'transit_min_days' => 0, 'transit_max_days' => 0]);

    $express = quoteTo(parcelListing('40.00', 500), 'Dubai', method: 'express')['shipments'][0];
    expect($express['method'])->toBe('express')
        ->and($express['shipping']['amount'])->toBe('25.00')
        ->and(array_column($express['shipping_options'], 'method'))->toBe(['standard', 'express']);

    $sameDay = quoteTo(parcelListing('40.00', 500, attributes: ['handling_days' => 0]), 'Dubai', method: 'same_day')['shipments'][0];
    expect($sameDay['method'])->toBe('same_day')
        ->and($sameDay['shipping']['amount'])->toBe('35.00')
        ->and($sameDay['delivery']['by'])->toBe('2026-11-16');

    $handled = quoteTo(parcelListing('40.00', 500), 'Dubai', method: 'same_day')['shipments'][0];
    expect($handled['method'])->toBe('standard')
        ->and($handled['shipping']['amount'])->toBe('15.00');
});

it('rejects an unknown shipping method', function () {
    $this->putJson('/api/v1/uae-en/cart/shipping', ['city' => 'Dubai', 'method' => 'drone'])
        ->assertJsonValidationErrors(['method'], 'error.fields');
});

it('ships free over the order threshold with the platform absorbing the engine cost', function () {
    [, $token] = customerWithToken();
    ShippingRate::factory()->for(ShippingZone::factory()->covering(['Dubai']), 'zone')->create(['base_fee' => '15.00', 'base_weight_kg' => '1', 'per_kg_fee' => '2.00']);
    $listing = parcelListing('120.00', 2300);

    $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1]);
    $this->withToken($token)->putJson('/api/v1/uae-en/cart/shipping', ['city' => 'Dubai'])
        ->assertJsonPath('data.shipments.0.shipping_cost.amount', '19.00')
        ->assertJsonPath('data.shipments.0.shipping.amount', '0.00')
        ->assertJsonPath('data.shipments.0.free_shipping_threshold_applied', true);

    $this->withToken($token)->postJson('/api/v1/uae-en/orders', ['payment_method' => 'card', 'address' => ['name' => 'Sara', 'city' => 'Dubai', 'area' => 'Marina', 'street' => 'Gate 2']])
        ->assertCreated()
        ->assertJsonPath('data.totals.shipping.amount', '0.00')
        ->assertJsonPath('data.totals.total.amount', '120.00');

    expect(SubOrder::query()->sole())
        ->shipping_cost->toBe('19.0000')
        ->shipping_absorbed->toBe('19.0000')
        ->shipping_total->toBe('0.0000');
});

it('gives free delivery in an exceptional zone to opted-in sellers and splits the subsidy', function () {
    $zone = ShippingZone::factory()->covering(['Al Ain'])->exceptional('40')->create();
    ShippingRate::factory()->for($zone, 'zone')->create(['base_fee' => '15.00', 'base_weight_kg' => '1', 'per_kg_fee' => '2.00']);
    $optedIn = Vendor::factory()->create();
    $zone->optedInVendors()->attach($optedIn);
    [, $token] = customerWithToken();

    $subsidised = parcelListing('30.00', 2300, vendor: $optedIn);
    $regular = parcelListing('30.00', 2300);
    $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $subsidised->id, 'quantity' => 1]);
    $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $regular->id, 'quantity' => 1]);

    $this->withToken($token)->postJson('/api/v1/uae-en/orders', ['payment_method' => 'card', 'address' => ['name' => 'Sara', 'city' => 'Al Ain', 'area' => 'Jimi', 'street' => 'Villa 3']])
        ->assertCreated()
        ->assertJsonPath('data.totals.shipping.amount', '19.00')
        ->assertJsonPath('data.totals.total.amount', '79.00');

    $subOrder = SubOrder::query()->where('vendor_id', $optedIn->id)->sole();
    expect($subOrder)
        ->shipping_total->toBe('0.0000')
        ->shipping_cost->toBe('19.0000')
        ->shipping_subsidy_vendor->toBe('7.6000')
        ->shipping_subsidy_platform->toBe('11.4000')
        ->and($subOrder->order->shipping_subsidy_total)->toBe('19.0000')
        ->and(SubOrder::query()->where('vendor_id', $regular->vendor_id)->sole()->shipping_total)->toBe('19.0000');
});

it('rounds engine fees and subsidy shares to 3 decimals in Oman', function () {
    $this->actAsCountry('omn');
    $zone = ShippingZone::factory()->covering(['Muscat'])->exceptional('33.33')->create();
    ShippingRate::factory()->for($zone, 'zone')->create(['base_fee' => '1.250', 'base_weight_kg' => '1', 'per_kg_fee' => '0.350']);
    ShippingSurcharge::query()->create(['name' => 'Fuel', 'type' => SurchargeType::Percent, 'value' => '7.5']);
    $vendor = Vendor::factory()->create();
    [, $token] = customerWithToken();
    $listing = parcelListing('4.255', 2300, vendor: $vendor);

    $this->withToken($token)->putJson('/api/v1/omn-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1]);
    $this->withToken($token)->putJson('/api/v1/omn-en/cart/shipping', ['city' => 'Muscat'])
        ->assertJsonPath('data.shipments.0.shipping', ['amount' => '2.096', 'currency' => 'OMR'])
        ->assertJsonPath('data.totals.total.amount', '6.351');

    $zone->optedInVendors()->attach($vendor);
    $this->withToken($token)->postJson('/api/v1/omn-en/orders', ['payment_method' => 'card', 'address' => ['name' => 'Salim', 'city' => 'Muscat', 'area' => 'Qurum', 'street' => 'Way 3011']])
        ->assertCreated()
        ->assertJsonPath('data.totals.total.amount', '4.255');

    expect(SubOrder::query()->sole())
        ->shipping_subsidy_vendor->toBe('0.6990')
        ->shipping_subsidy_platform->toBe('1.3970');
});

it('quotes shipping to the order address, not the address typed in the cart', function () {
    ShippingRate::factory()->for(ShippingZone::factory()->covering(['Dubai']), 'zone')->create(['base_fee' => '12.00', 'per_kg_fee' => '0']);
    ShippingRate::factory()->for(ShippingZone::factory()->covering(['Ajman']), 'zone')->create(['base_fee' => '30.00', 'per_kg_fee' => '0']);
    [, $token] = customerWithToken();
    $listing = parcelListing('40.00', 500);

    $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1]);
    $this->withToken($token)->putJson('/api/v1/uae-en/cart/shipping', ['city' => 'Dubai'])->assertJsonPath('data.totals.shipping.amount', '12.00');

    $this->withToken($token)->postJson('/api/v1/uae-en/orders', ['payment_method' => 'card', 'address' => ['name' => 'Sara', 'city' => 'Ajman', 'area' => 'Nuaimiya', 'street' => 'Tower 1']])
        ->assertCreated()
        ->assertJsonPath('data.totals.shipping.amount', '30.00')
        ->assertJsonPath('data.shipments.0.method', 'standard');
});

it('keeps each country\'s zones to itself', function () {
    ShippingRate::factory()->for(ShippingZone::factory()->asDefault(), 'zone')->create(['base_fee' => '30.00', 'per_kg_fee' => '0']);
    $this->actAsCountry('ksa');

    expect(ShippingZone::query()->count())->toBe(0)
        ->and(quoteTo(parcelListing('40.00', 500), 'Riyadh', market: 'ksa-en')['shipments'][0]['shipping']['amount'])->toBe('10.00');
});

it('treats a variant without weight or dimensions as weightless', function () {
    ShippingRate::factory()->for(ShippingZone::factory()->covering(['Dubai']), 'zone')->create(['base_fee' => '15.00', 'base_weight_kg' => '0', 'per_kg_fee' => '2.00']);
    $listing = sellableListing('40.00');
    ProductVariant::query()->whereKey($listing->product_variant_id)->update(['weight_grams' => null]);

    expect(quoteTo($listing, 'Dubai')['shipments'][0]['shipping']['amount'])->toBe('15.00');
});
