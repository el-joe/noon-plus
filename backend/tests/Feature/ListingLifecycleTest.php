<?php

use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Exceptions\ListingTransitionRefused;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\ListingStateMachine;
use App\Domains\Vendors\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->actAsCountry('uae');
});

it('takes a listing from draft through review to active and records each step with the actor', function () {
    $listing = Listing::factory()->create();
    $machine = app(ListingStateMachine::class);

    $machine->transition($listing, ListingStatus::PendingReview, actorUserId: 7);
    $machine->transition($listing, ListingStatus::Active, actorUserId: 1);

    expect($listing->fresh()->status)->toBe(ListingStatus::Active)
        ->and($listing->statusHistory()->orderBy('id')->get()->map(fn ($change) => [$change->from_status, $change->to_status, $change->actor_user_id])->all())
        ->toBe([
            [ListingStatus::Draft, ListingStatus::PendingReview, 7],
            [ListingStatus::PendingReview, ListingStatus::Active, 1],
        ]);
});

it('refuses transitions the lifecycle does not allow', function (ListingStatus $from, ListingStatus $to) {
    $listing = Listing::factory()->create(['status' => $from]);

    app(ListingStateMachine::class)->transition($listing, $to);
})->with([
    'draft straight to active' => [ListingStatus::Draft, ListingStatus::Active],
    'archived back to active' => [ListingStatus::Archived, ListingStatus::Active],
    'rejected straight to active' => [ListingStatus::Rejected, ListingStatus::Active],
])->throws(ListingTransitionRefused::class);

it('does not approve a listing while the seller account is not active', function () {
    $listing = Listing::factory()->for(Vendor::factory()->pending())->create(['status' => ListingStatus::PendingReview]);

    expect(fn () => app(ListingStateMachine::class)->transition($listing, ListingStatus::Active))
        ->toThrow(ListingTransitionRefused::class, 'The seller account is not active.');
    expect($listing->fresh()->status)->toBe(ListingStatus::PendingReview);
});

it('requires a reason to reject and keeps it for the seller', function () {
    $listing = Listing::factory()->create(['status' => ListingStatus::PendingReview]);
    $machine = app(ListingStateMachine::class);

    expect(fn () => $machine->transition($listing, ListingStatus::Rejected))->toThrow(ListingTransitionRefused::class);

    $machine->transition($listing, ListingStatus::Rejected, reason: 'Main image has a watermark');

    expect($listing->fresh()->rejection_reason)->toBe('Main image has a watermark');
});

it('does not send a zero-price listing to review', function () {
    $listing = Listing::factory()->create(['price' => '0']);

    app(ListingStateMachine::class)->transition($listing, ListingStatus::PendingReview);
})->throws(ListingTransitionRefused::class);
