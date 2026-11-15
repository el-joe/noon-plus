<?php

use App\Domains\Catalog\Models\Category;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Models\CountryCalendar;
use App\Domains\Shipping\Enums\ShippingMethod;
use App\Domains\Shipping\Models\ShippingRate;
use App\Domains\Shipping\Models\ShippingZone;
use App\Domains\Warranty\Models\WarrantyPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->uae = $this->actAsCountry('uae');
});

/**
 * @return array<string, mixed>
 */
function ratePayload(array $overrides = []): array
{
    return $overrides + [
        'method' => 'standard', 'base_fee' => '15.00', 'base_weight_kg' => '2', 'per_kg_fee' => '3.50',
        'transit_min_days' => 1, 'transit_max_days' => 3, 'cutoff_time' => '14:00', 'is_active' => '1',
    ];
}

it('links the settings screens the admin may open', function () {
    $this->actingAs(adminAccount())->get(onPanel('admin', 'settings'))
        ->assertOk()->assertSee('Shipping zones &amp; rates', false)->assertSee('Holidays')->assertSee('Warranty plans')->assertSee('Flash sales');

    $this->actingAs(adminAccount('uae', 'marketing'))->get(onPanel('admin', 'settings'))
        ->assertOk()->assertSee('Flash sales')->assertDontSee('Warranty plans');
});

it('creates and edits shipping zones with a single default zone', function () {
    $old = ShippingZone::factory()->create(['is_default' => true]);
    $this->actingAs(adminAccount());

    $this->post(onPanel('admin', 'delivery/zones'), ['name' => 'Dubai', 'vendor_subsidy_share_percent' => '40', 'is_default' => '1', 'is_active' => '1'])->assertRedirect();
    $zone = ShippingZone::query()->where('name', 'Dubai')->sole();
    expect($zone->is_default)->toBeTrue()->and($old->fresh()->is_default)->toBeFalse();

    $this->put(onPanel('admin', "delivery/zones/{$zone->id}"), ['name' => 'Dubai & Sharjah', 'vendor_subsidy_share_percent' => '50', 'is_exceptional' => '1', 'is_active' => '0'])->assertSessionHasNoErrors();
    expect($zone->fresh())->name->toBe('Dubai & Sharjah')->is_exceptional->toBeTrue()->is_active->toBeFalse()->is_default->toBeFalse();

    $this->post(onPanel('admin', 'delivery/zones'), ['name' => '', 'vendor_subsidy_share_percent' => '120'])->assertSessionHasErrors(['name', 'vendor_subsidy_share_percent']);
    $this->get(onPanel('admin', 'delivery'))->assertOk()->assertSee('Dubai &amp; Sharjah', false);
});

it('adds and removes zone areas', function () {
    $zone = ShippingZone::factory()->create();
    $this->actingAs(adminAccount());

    $this->post(onPanel('admin', "delivery/zones/{$zone->id}/areas"), ['city' => 'Dubai', 'area' => 'Marina'])->assertSessionHasNoErrors();
    $this->post(onPanel('admin', "delivery/zones/{$zone->id}/areas"), ['city' => 'Dubai', 'area' => 'Marina'])->assertSessionHasErrors('area');
    $area = $zone->areas()->sole();

    $this->get(onPanel('admin', "delivery/zones/{$zone->id}"))->assertOk()->assertSee('Marina');
    $this->delete(onPanel('admin', "delivery/areas/{$area->id}"))->assertSessionHasNoErrors();
    expect($zone->areas()->count())->toBe(0);
});

it('adds, edits and removes one rate per method', function () {
    $zone = ShippingZone::factory()->create();
    $this->actingAs(adminAccount());

    $this->post(onPanel('admin', "delivery/zones/{$zone->id}/rates"), ratePayload())->assertSessionHasNoErrors();
    $this->post(onPanel('admin', "delivery/zones/{$zone->id}/rates"), ratePayload())->assertSessionHasErrors('method');
    $rate = ShippingRate::query()->sole();
    expect($rate->method)->toBe(ShippingMethod::Standard)->and((string) $rate->base_fee)->toBe('15.0000');

    $this->put(onPanel('admin', "delivery/rates/{$rate->id}"), ratePayload(['base_fee' => '18', 'transit_max_days' => 5, 'cutoff_time' => '']))->assertSessionHasNoErrors();
    expect($rate->fresh())->base_fee->toEqual('18.0000')->transit_max_days->toBe(5)->cutoff_time->toBeNull();

    $this->put(onPanel('admin', "delivery/rates/{$rate->id}"), ratePayload(['transit_min_days' => 4, 'transit_max_days' => 2]))->assertSessionHasErrors('transit_max_days');
    $this->delete(onPanel('admin', "delivery/rates/{$rate->id}"));
    expect(ShippingRate::query()->count())->toBe(0);
});

it('lets finance view zones but only operations change them', function () {
    $zone = ShippingZone::factory()->create();

    $this->actingAs(adminAccount('uae', 'finance'))->get(onPanel('admin', "delivery/zones/{$zone->id}"))->assertOk()->assertDontSee('Add area');
    $this->post(onPanel('admin', 'delivery/zones'), ['name' => 'X', 'vendor_subsidy_share_percent' => '0'])->assertForbidden();
    $this->actingAs(adminAccount('uae', 'marketing'))->get(onPanel('admin', 'delivery'))->assertForbidden();
    $this->actingAs(adminAccount('uae', 'operations'))->post(onPanel('admin', "delivery/zones/{$zone->id}/areas"), ['city' => 'Abu Dhabi'])->assertSessionHasNoErrors();
});

it('manages the holidays of the selected country', function () {
    $this->actingAs(adminAccount());

    $this->post(onPanel('admin', 'settings/holidays'), ['name_en' => 'National Day', 'name_ar' => 'اليوم الوطني', 'starts_on' => '2026-12-02', 'ends_on' => '2026-12-03', 'is_confirmed' => '1'])
        ->assertSessionHasNoErrors();
    $holiday = CountryCalendar::query()->where('country_id', $this->uae->id)->sole();
    expect($holiday->ends_on->toDateString())->toBe('2026-12-03')->and($holiday->is_confirmed)->toBeTrue();

    $this->put(onPanel('admin', "settings/holidays/{$holiday->id}"), ['name_en' => 'Eid Al Etihad', 'name_ar' => 'عيد الاتحاد', 'starts_on' => '2026-12-02'])->assertSessionHasNoErrors();
    expect($holiday->fresh())->name_en->toBe('Eid Al Etihad')->ends_on->toDateString()->toBe('2026-12-02');

    $this->get(onPanel('admin', 'settings/holidays?year=2026'))->assertOk()->assertSee('Eid Al Etihad');
    $this->post(onPanel('admin', 'settings/holidays'), ['name_en' => 'Bad', 'name_ar' => 'x', 'starts_on' => '2026-12-05', 'ends_on' => '2026-12-01'])->assertSessionHasErrors('ends_on');

    $this->delete(onPanel('admin', "settings/holidays/{$holiday->id}"));
    expect(CountryCalendar::query()->count())->toBe(0);
});

it('never edits another country\'s holiday from the selected country', function () {
    $omanHoliday = CountryCalendar::factory()->create(['country_id' => Country::query()->where('code', 'omn')->value('id')]);

    $this->actingAs(adminAccount())->put(onPanel('admin', "settings/holidays/{$omanHoliday->id}?country=uae"), ['name_en' => 'Hacked', 'name_ar' => 'x', 'starts_on' => '2026-12-02'])->assertNotFound();
    $this->delete(onPanel('admin', "settings/holidays/{$omanHoliday->id}"))->assertNotFound();

    expect($omanHoliday->fresh()->name_en)->toBe('National Day');
});

it('keeps holiday changes to super admins; operations can only view', function () {
    $this->actingAs(adminAccount('uae', 'operations'))->get(onPanel('admin', 'settings/holidays'))->assertOk()->assertDontSee('Add a holiday');
    $this->post(onPanel('admin', 'settings/holidays'), ['name_en' => 'X', 'name_ar' => 'x', 'starts_on' => '2026-12-02'])->assertForbidden();
});

it('creates and updates warranty plans per parent category', function () {
    $electronics = Category::factory()->create(['name_en' => 'Electronics']);
    $this->actingAs(adminAccount());

    $this->post(onPanel('admin', 'catalog/warranty-plans'), ['category_id' => $electronics->id, 'name_en' => '2-year care', 'name_ar' => 'رعاية سنتين', 'duration_months' => 24, 'price' => '99.00', 'is_active' => '1'])
        ->assertSessionHasNoErrors();
    $plan = WarrantyPlan::query()->sole();
    expect($plan)->duration_months->toBe(24)->price->toEqual('99.0000')->is_active->toBeTrue();

    $this->put(onPanel('admin', "catalog/warranty-plans/{$plan->id}"), ['category_id' => $electronics->id, 'name_en' => '2-year care', 'name_ar' => 'رعاية سنتين', 'duration_months' => 24, 'price' => '89.50', 'is_active' => '0'])
        ->assertSessionHasNoErrors();
    expect($plan->fresh())->price->toEqual('89.5000')->is_active->toBeFalse();

    $this->get(onPanel('admin', 'catalog/warranty-plans'))->assertOk()->assertSee('2-year care');
    $this->post(onPanel('admin', 'catalog/warranty-plans'), ['category_id' => 99999, 'name_en' => 'x', 'name_ar' => 'x', 'duration_months' => 0, 'price' => '-1'])
        ->assertSessionHasErrors(['category_id', 'duration_months', 'price']);
});

it('lets finance view warranty plans but not change them', function () {
    $plan = WarrantyPlan::factory()->create();

    $this->actingAs(adminAccount('uae', 'finance'))->get(onPanel('admin', 'catalog/warranty-plans'))->assertOk();
    $this->put(onPanel('admin', "catalog/warranty-plans/{$plan->id}"), ['category_id' => $plan->category_id, 'name_en' => 'x', 'name_ar' => 'x', 'duration_months' => 1, 'price' => '1'])->assertForbidden();
});
