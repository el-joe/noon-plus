<?php

use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Listings\Support\ListingStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->actAsCountry('uae');
});

function pendingListing(int $stock = 3): Listing
{
    $listing = Listing::factory()->create();
    if ($stock > 0) {
        app(InventoryLedger::class)->move($listing, $stock, 'initial_stock');
    }
    app(ListingStateMachine::class)->transition($listing, ListingStatus::PendingReview, 7);

    return $listing->fresh();
}

it('queues listings waiting for review in the selected country only', function () {
    $uaeListing = pendingListing();
    $active = sellableListing();
    $this->actAsCountry('omn');
    $omanListing = pendingListing();

    $this->actingAs(adminAccount())->get(onPanel('admin', 'approvals?country=uae'))
        ->assertOk()
        ->assertSee('data-listing="'.$uaeListing->id.'"', false)
        ->assertDontSee('data-listing="'.$active->id.'"', false)
        ->assertSee('United Arab Emirates');

    $this->get(onPanel('admin', 'approvals?country=omn'))->assertOk()->assertSee('data-listing="'.$omanListing->id.'"', false)->assertSee('Oman');
});

it('approves a listing and records the admin as actor', function () {
    $listing = pendingListing();
    $admin = adminAccount();

    $this->actingAs($admin)->post(onPanel('admin', "approvals/listings/{$listing->id}/approve"))->assertSessionHasNoErrors();

    expect($listing->fresh()->status)->toBe(ListingStatus::Active)
        ->and($listing->statusHistory()->latest('id')->first()->actor_user_id)->toBe($admin->id);
});

it('sends an approved listing without stock straight to out of stock', function () {
    $listing = pendingListing(stock: 0);

    $this->actingAs(adminAccount())->post(onPanel('admin', "approvals/listings/{$listing->id}/approve"));

    expect($listing->fresh()->status)->toBe(ListingStatus::OutOfStock);
});

it('refuses approving a listing whose seller is not active', function () {
    $listing = pendingListing();
    $listing->vendor->update(['status' => 'suspended']);

    $this->actingAs(adminAccount())->post(onPanel('admin', "approvals/listings/{$listing->id}/approve"))
        ->assertSessionHasErrors(['action' => 'The seller account is not active.']);

    expect($listing->fresh()->status)->toBe(ListingStatus::PendingReview);
});

it('rejects a listing with a reason the seller can read', function () {
    $listing = pendingListing();

    $this->actingAs(adminAccount())->post(onPanel('admin', "approvals/listings/{$listing->id}/reject"), ['reason' => ''])->assertSessionHasErrors('reason');
    expect($listing->fresh()->status)->toBe(ListingStatus::PendingReview);

    $this->post(onPanel('admin', "approvals/listings/{$listing->id}/reject"), ['reason' => 'Images are missing'])->assertSessionHasNoErrors();
    expect($listing->fresh())
        ->status->toBe(ListingStatus::Rejected)
        ->rejection_reason->toBe('Images are missing');
});

it('lets country admins with the right role review only in their country', function () {
    $listing = pendingListing();
    $reviewer = adminAccount('uae', 'vendor_relations');

    $this->actingAs($reviewer)->get(onPanel('admin', 'approvals'))->assertOk();
    $this->post(onPanel('admin', "approvals/listings/{$listing->id}/approve"))->assertSessionHasNoErrors();
    expect($listing->fresh()->status)->toBe(ListingStatus::Active);

    $omanReviewer = adminAccount('omn', 'vendor_relations');
    $other = pendingListing();
    $this->actingAs($omanReviewer)->post(onPanel('admin', "approvals/listings/{$other->id}/approve?country=uae"));
    $this->actAsCountry('uae');
    expect($other->fresh()->status)->toBe(ListingStatus::PendingReview);
});

it('forbids the queue to admins without the permission and to sellers', function () {
    $listing = pendingListing();
    [$seller] = sellerAccount('uae');

    $this->actingAs(adminAccount('uae', 'support'))->get(onPanel('admin', 'approvals'))->assertForbidden();
    $this->post(onPanel('admin', "approvals/listings/{$listing->id}/approve"))->assertForbidden();
    $this->actingAs(adminAccount('uae', 'finance'))->get(onPanel('admin', 'approvals'))->assertForbidden();
    $this->actingAs($seller)->get(onPanel('admin', 'approvals'))->assertForbidden();
    $this->actingAs(adminAccount('uae'))->get(onPanel('admin', 'approvals'))->assertForbidden();

    $this->actAsCountry('uae');
    expect($listing->fresh()->status)->toBe(ListingStatus::PendingReview);
});
