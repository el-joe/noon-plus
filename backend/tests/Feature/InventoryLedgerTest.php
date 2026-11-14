<?php

use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Exceptions\InsufficientStock;
use App\Domains\Listings\Models\InventoryMovement;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\InventoryLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->actAsCountry('omn');
});

it('marks an active listing out of stock when the last unit is sold and active again on restock', function () {
    $listing = Listing::factory()->active()->create();
    $ledger = app(InventoryLedger::class);

    expect($ledger->move($listing, 2, 'restock'))->toBe(2);
    expect($ledger->move($listing, -2, 'sale', 'order_item', 501))->toBe(0);
    expect($listing->fresh()->status)->toBe(ListingStatus::OutOfStock);

    $ledger->move($listing, 5, 'restock');
    expect($listing->fresh()->status)->toBe(ListingStatus::Active)
        ->and($ledger->available($listing))->toBe(5);
});

it('refuses to sell more than is in stock and records nothing', function () {
    $listing = Listing::factory()->active()->create();
    $ledger = app(InventoryLedger::class);
    $ledger->move($listing, 1, 'restock');

    expect(fn () => $ledger->move($listing, -3, 'sale'))->toThrow(InsufficientStock::class);
    expect($listing->movements()->count())->toBe(1);
});

it('never lets a stock movement be changed or deleted', function () {
    $listing = Listing::factory()->active()->create();
    app(InventoryLedger::class)->move($listing, 4, 'restock');
    $movement = InventoryMovement::query()->sole();

    expect(fn () => $movement->update(['quantity' => 40]))->toThrow(LogicException::class)
        ->and(fn () => $movement->delete())->toThrow(LogicException::class);
});
