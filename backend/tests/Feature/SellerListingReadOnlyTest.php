<?php

use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Panels\Support\PanelCountries;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
    Storage::fake('public');
});

/*
| Browser test BT-VEN-008: a read-only team member (products: view) saw the
| price, stock and image forms on a listing page and only met a 403 on submit.
*/
it('shows a read-only member the listing without its change forms, and refuses the changes', function () {
    [$owner, $vendor] = sellerAccount();
    $listing = sellableListing('100.00', stock: 5, vendor: $vendor);
    $orders = User::factory()->create();
    $orders->memberships()->create(['role' => MembershipRole::ProductVendor, 'country_id' => $this->country->id, 'tenant_type' => PanelCountries::VENDOR_TENANT, 'tenant_id' => $vendor->id, 'tenant_role' => 'orders']);

    $this->actingAs($orders)->get(onPanel('partner', "products/{$listing->id}/edit"))->assertOk()
        ->assertSee('data-read-only', false)
        ->assertDontSee('action="'.route('partner.products.update', $listing->id).'"', false)
        ->assertDontSee('action="'.route('partner.products.stock', $listing->id).'"', false)
        ->assertDontSee('action="'.route('partner.products.images.store', $listing->id).'"', false)
        ->assertDontSee('action="'.route('partner.products.status', $listing->id).'"', false)
        ->assertSee('Handling days');

    $this->put(onPanel('partner', "products/{$listing->id}"), ['price' => '90', 'handling_days' => 1])->assertForbidden();
    $this->post(onPanel('partner', "products/{$listing->id}/stock"), ['quantity' => 3, 'reason' => 'restock'])->assertForbidden();
    $this->post(onPanel('partner', "products/{$listing->id}/status"), ['action' => 'pause'])->assertForbidden();
    expect((string) $listing->fresh()->price)->toBe('100.0000');

    $this->actingAs($owner)->get(onPanel('partner', "products/{$listing->id}/edit"))->assertOk()
        ->assertDontSee('data-read-only', false)
        ->assertSee('action="'.route('partner.products.update', $listing->id).'"', false)
        ->assertSee('action="'.route('partner.products.stock', $listing->id).'"', false)
        ->assertSee('action="'.route('partner.products.images.store', $listing->id).'"', false);
});
