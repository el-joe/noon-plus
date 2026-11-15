<?php

use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Models\CountryCalendar;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Settings\SettingsService;
use App\Domains\Shipping\Models\ShippingRate;
use App\Domains\Shipping\Models\ShippingZone;
use App\Domains\Shipping\Support\DeliveryEstimator;
use App\Domains\Vendors\Models\Vendor;
use Carbon\CarbonImmutable;
use Database\Seeders\CountryCalendarSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->actAsCountry('uae');
});

function holiday(string $countryCode, string $startsOn, ?string $endsOn = null): CountryCalendar
{
    return CountryCalendar::factory()
        ->for(Country::query()->where('code', $countryCode)->sole())
        ->between($startsOn, $endsOn)
        ->create();
}

/**
 * Next-business-day delivery (no processing, 1 transit day) ordered at a country-local time.
 */
function nextDayEta(string $countryCode, string $localDateTime): string
{
    $country = Country::query()->where('code', $countryCode)->sole();

    return app(DeliveryEstimator::class)
        ->estimate($country, CarbonImmutable::parse($localDateTime, $country->timezone), 0, 1, 1)
        ->by->toDateString();
}

it('counts business days with each country\'s weekend and holidays', function (string $country, string $orderedAt, string $expected) {
    holiday('omn', '2026-11-29', '2026-11-30');
    holiday('uae', '2026-12-01', '2026-12-03');

    expect(nextDayEta($country, $orderedAt))->toBe($expected);
})->with([
    'UAE Thursday morning: Friday is a working day' => ['uae', '2026-11-19 10:00', '2026-11-20'],
    'Oman Thursday morning: skips the Friday–Saturday weekend' => ['omn', '2026-11-19 10:00', '2026-11-22'],
    'UAE Friday morning: skips the Saturday–Sunday weekend' => ['uae', '2026-11-20 10:00', '2026-11-23'],
    'Oman Thursday with a Sunday–Monday holiday' => ['omn', '2026-11-26 10:00', '2026-12-01'],
    'UAE Saturday order: dispatched Monday' => ['uae', '2026-11-21 10:00', '2026-11-24'],
    'UAE order on the first day of a 3-day holiday' => ['uae', '2026-12-01 10:00', '2026-12-07'],
    'another country\'s holiday does not count' => ['uae', '2026-11-27 10:00', '2026-11-30'],
]);

it('dispatches from the next business day after the cut-off', function (string $country, string $orderedAt, string $expected) {
    expect(nextDayEta($country, $orderedAt))->toBe($expected);
})->with([
    'UAE 13:59 still counts today' => ['uae', '2026-11-19 13:59', '2026-11-20'],
    'UAE 14:00 is after the cut-off' => ['uae', '2026-11-19 14:00', '2026-11-23'],
    'Oman Thursday 15:00 goes out Sunday' => ['omn', '2026-11-19 15:00', '2026-11-23'],
]);

it('reads the cut-off from the country setting and lets a rate override it', function () {
    $uae = Country::query()->where('code', 'uae')->sole();
    app(SettingsService::class)->set('shipping.eta_cutoff_time', '09:00', $uae);
    $estimator = app(DeliveryEstimator::class);
    $tenAm = CarbonImmutable::parse('2026-11-19 10:00', 'Asia/Dubai');

    expect($estimator->estimate($uae, $tenAm, 0, 1, 1)->by->toDateString())->toBe('2026-11-23')
        ->and($estimator->estimate($uae, $tenAm, 0, 1, 1, '18:00:00')->by->toDateString())->toBe('2026-11-20');
});

it('tells the customer until when ordering still counts for today', function () {
    holiday('omn', '2026-11-19');
    $estimator = app(DeliveryEstimator::class);
    $uae = Country::query()->where('code', 'uae')->sole();
    $oman = Country::query()->where('code', 'omn')->sole();

    expect($estimator->estimate($uae, CarbonImmutable::parse('2026-11-19 10:00', 'Asia/Dubai'), 1, 1, 2)->toArray())
        ->toBe(['from' => '2026-11-23', 'by' => '2026-11-24', 'order_before' => '2026-11-19T10:00:00+00:00'])
        ->and($estimator->estimate($oman, CarbonImmutable::parse('2026-11-19 10:00', 'Asia/Muscat'), 1, 1, 2)->orderBefore)->toBeNull();
});

it('shows "get it by" per shipment in the cart and keeps it on the order', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00', 'Asia/Dubai'));
    ShippingRate::factory()->for(ShippingZone::factory()->covering(['Dubai']), 'zone')->create(['transit_min_days' => 1, 'transit_max_days' => 2]);
    [, $token] = customerWithToken();
    $listing = sellableListing('40.00', attributes: ['handling_days' => 1]);

    $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1]);
    $this->withToken($token)->putJson('/api/v1/uae-en/cart/shipping', ['city' => 'Dubai'])
        ->assertJsonPath('data.shipments.0.delivery.from', '2026-11-23')
        ->assertJsonPath('data.shipments.0.delivery.by', '2026-11-24')
        ->assertJsonPath('data.shipments.0.shipping_options.0.delivery.by', '2026-11-24');

    $this->withToken($token)->postJson('/api/v1/uae-en/orders', ['payment_method' => 'cod', 'address' => ['name' => 'Sara', 'city' => 'Dubai', 'area' => 'Marina', 'street' => 'Gate 2']])
        ->assertCreated()
        ->assertJsonPath('data.shipments.0.delivery', ['from' => '2026-11-23', 'by' => '2026-11-24']);

    expect(SubOrder::query()->sole()->estimated_delivery_by->toDateString())->toBe('2026-11-24');
});

it('lets the slowest item set the shipment date, adding product lead time', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00', 'Asia/Dubai'));
    ShippingRate::factory()->for(ShippingZone::factory()->asDefault(), 'zone')->create(['transit_min_days' => 1, 'transit_max_days' => 1]);
    $vendor = Vendor::factory()->create();
    $quick = sellableListing('40.00', attributes: ['handling_days' => 0], vendor: $vendor);
    $madeToOrder = sellableListing('90.00', attributes: ['handling_days' => 1], vendor: $vendor);
    $madeToOrder->variant->product->forceFill(['fulfilment_mode' => 'made_to_order', 'lead_days' => 3])->save();

    $token = $this->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $quick->id, 'quantity' => 1])->headers->get('X-Cart-Token');
    $this->withHeader('X-Cart-Token', $token)->getJson('/api/v1/uae-en/cart')->assertJsonPath('data.shipments.0.delivery.by', '2026-11-20');
    $this->withHeader('X-Cart-Token', $token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $madeToOrder->id, 'quantity' => 1])
        ->assertJsonPath('data.shipments.0.delivery.by', '2026-11-26');
});

it('promises a delivery date on the product page, with the fallback transit days when no zone exists', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00', 'Asia/Dubai'));
    $listing = sellableListing('40.00', attributes: ['handling_days' => 1]);

    $this->getJson('/api/v1/uae-en/products/'.$listing->variant->product->slug)
        ->assertOk()
        ->assertJsonPath('data.buy_box.delivery.from', '2026-11-24')
        ->assertJsonPath('data.buy_box.delivery.by', '2026-11-26')
        ->assertJsonPath('data.buy_box.delivery.order_before', '2026-11-19T10:00:00+00:00');
});

it('seeds the holiday placeholders for every country, unconfirmed', function () {
    $this->seed(CountryCalendarSeeder::class);

    expect(CountryCalendar::query()->distinct()->count('country_id'))->toBe(8)
        ->and(CountryCalendar::query()->where('is_confirmed', true)->count())->toBe(0)
        ->and(nextDayEta('omn', '2026-11-19 10:00'))->toBe('2026-11-22')
        ->and(nextDayEta('uae', '2026-11-30 10:00'))->toBe('2026-12-04');
});
