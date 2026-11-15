<?php

use App\Domains\Countries\Models\Country;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Vendors\Models\VendorStrike;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
});

it('shows the store dashboard with KPIs from its own orders and listings', function () {
    [$user, $vendor] = sellerAccount('uae', ['store_name' => 'SoundMax']);
    $this->actAsCountry('uae');
    subOrderFor($vendor);
    subOrderFor($vendor, SubOrderStatus::Shipped);
    subOrderFor($vendor, SubOrderStatus::Cancelled);
    [, $otherVendor] = sellerAccount('uae');
    subOrderFor($otherVendor);
    VendorStrike::query()->create(['vendor_id' => $vendor->id, 'reason' => 'seller_cancellation', 'points' => 5]);

    $this->actingAs($user)->get(onPanel('partner'))
        ->assertOk()
        ->assertSee('SoundMax')
        ->assertSeeInOrder(['Sales today', 'AED 200.00'])
        ->assertSeeInOrder(['Orders to confirm', '1'])
        ->assertSeeInOrder(['Active listings', '3'])
        ->assertSee('95 / 100')
        ->assertSee('1 order to confirm');
});

it('binds the request to the store of the selected country and switches between countries', function () {
    [$user, $uaeStore] = sellerAccount('uae', ['store_name' => 'Gulf Gadgets']);
    [, $omanStore] = sellerAccount('omn', ['store_name' => 'Muscat Gadgets'], $user);

    $this->actingAs($user)->get(onPanel('partner'))->assertOk()->assertSee('Gulf Gadgets')->assertSee('data-country-switcher', false);

    $this->post(onPanel('partner', 'country'), ['country' => 'omn'])->assertRedirect(rtrim(onPanel('partner'), '/'));
    $this->get(onPanel('partner'))->assertOk()->assertSee('Muscat Gadgets')->assertDontSee('Gulf Gadgets');
});

it('refuses switching to a country the seller has no store in', function () {
    [$user] = sellerAccount('uae', ['store_name' => 'Gulf Gadgets']);

    $this->actingAs($user)->post(onPanel('partner', 'country'), ['country' => 'ksa'])->assertSessionHasErrors('country');
    $this->get(onPanel('partner', '?country=ksa'))->assertOk()->assertSee('Gulf Gadgets');
});

it('keeps seller pages closed to members without a store in the country', function () {
    $user = User::factory()->create();
    $user->memberships()->create(['role' => MembershipRole::ProductVendor, 'country_id' => Country::query()->where('code', 'uae')->value('id')]);

    $this->actingAs($user)->get(onPanel('partner', 'products'))->assertForbidden();
    $this->actingAs($user)->get(onPanel('partner', 'orders'))->assertForbidden();
});

it('drops the store when its membership is suspended', function () {
    [$user] = sellerAccount('uae');
    [, $omanStore] = sellerAccount('omn', ['store_name' => 'Muscat Gadgets'], $user);
    $user->memberships()->where('country_id', Country::query()->where('code', 'uae')->value('id'))->update(['status' => 'suspended']);

    $this->actingAs($user)->get(onPanel('partner'))->assertOk()->assertSee('Muscat Gadgets');
    $this->get(onPanel('partner', '?country=uae'))->assertOk()->assertSee('Muscat Gadgets');
});

it('never shows or lets a seller touch another store\'s listings or orders', function () {
    [$user, $vendor] = sellerAccount('uae');
    [, $rival] = sellerAccount('uae');
    $this->actAsCountry('uae');
    $own = sellableListing('50.00', vendor: $vendor);
    $theirs = sellableListing('60.00', vendor: $rival);
    $theirOrder = subOrderFor($rival);

    $this->actingAs($user)->get(onPanel('partner', 'products'))
        ->assertOk()
        ->assertSee('data-listing="'.$own->id.'"', false)
        ->assertDontSee('data-listing="'.$theirs->id.'"', false);
    $this->get(onPanel('partner', "products/{$theirs->id}/edit"))->assertNotFound();
    $this->put(onPanel('partner', "products/{$theirs->id}"), ['price' => '1', 'handling_days' => 1])->assertNotFound();
    $this->post(onPanel('partner', "products/{$theirs->id}/status"), ['action' => 'pause'])->assertNotFound();
    $this->get(onPanel('partner', 'orders'))->assertDontSee($theirOrder->number);
    $this->get(onPanel('partner', "orders/{$theirOrder->id}"))->assertNotFound();
    $this->post(onPanel('partner', "orders/{$theirOrder->id}/cancel"), ['reason' => 'Not mine at all'])->assertNotFound();

    expect($theirs->fresh()->status)->toBe(ListingStatus::Active)
        ->and($theirOrder->fresh()->status)->toBe(SubOrderStatus::Placed);
});

it('isolates a seller\'s stores by country', function () {
    [$user, $uaeStore] = sellerAccount('uae');
    [, $omanStore] = sellerAccount('omn', user: $user);
    $this->actAsCountry('omn');
    $omanListing = sellableListing('5.000', vendor: $omanStore);

    $this->actingAs($user)->get(onPanel('partner', "products/{$omanListing->id}/edit"))->assertNotFound();
    $this->get(onPanel('partner', "products/{$omanListing->id}/edit?country=omn"))->assertOk()->assertSee('OMR');
});
