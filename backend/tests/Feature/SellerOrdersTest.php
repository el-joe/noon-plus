<?php

use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Vendors\Models\VendorStrike;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    [$this->seller, $this->vendor] = sellerAccount('uae');
    $this->actAsCountry('uae');
    $this->actingAs($this->seller);
});

it('lists the store orders with a status filter', function () {
    $toConfirm = subOrderFor($this->vendor);
    $packed = subOrderFor($this->vendor, SubOrderStatus::Packed);

    $this->get(onPanel('partner', 'orders'))->assertOk()->assertSee($toConfirm->number)->assertSee($packed->number);
    $this->get(onPanel('partner', 'orders?status=packed'))->assertOk()->assertSee($packed->number)->assertDontSee($toConfirm->number);
    $this->get(onPanel('partner', 'orders?status=teleported'))->assertSessionHasErrors('status');
});

it('shows the sub-order with its items, timeline and next step', function () {
    $subOrder = subOrderFor($this->vendor, quantity: 2);

    $this->get(onPanel('partner', "orders/{$subOrder->id}"))
        ->assertOk()
        ->assertSee($subOrder->items->first()->sku)
        ->assertSee('Confirm order')
        ->assertSee('contact hidden until shipped')
        ->assertDontSee('+971500000000')
        ->assertSeeInOrder(['data-state="current"', 'Placed', 'Customer', 'data-state="pending"', 'Confirmed'], false);
});

it('walks a sub-order from confirmed to ready for pickup through the state machine', function () {
    $subOrder = subOrderFor($this->vendor);

    foreach ([SubOrderStatus::Confirmed, SubOrderStatus::Processing, SubOrderStatus::Packed, SubOrderStatus::Shipped] as $step) {
        $this->post(onPanel('partner', "orders/{$subOrder->id}/advance"), ['to' => $step->value])->assertSessionHasNoErrors();
        expect($subOrder->fresh()->status)->toBe($step);
    }

    $history = $subOrder->statusHistory()->get();
    expect($history)->toHaveCount(5)
        ->and($history->last()->actor_type)->toBe(ActorType::Vendor)
        ->and($history->last()->actor_user_id)->toBe($this->seller->id)
        ->and($subOrder->items()->first()->fulfillment_status)->toBe('shipped')
        ->and($subOrder->order->fresh()->status)->toBe(OrderStatus::Shipped);

    $this->get(onPanel('partner', "orders/{$subOrder->id}"))->assertDontSee('Ready for pickup');
});

it('refuses skipping a fulfilment step or moving past the hand-off', function (SubOrderStatus $from, SubOrderStatus $to) {
    $subOrder = subOrderFor($this->vendor, $from);

    $this->post(onPanel('partner', "orders/{$subOrder->id}/advance"), ['to' => $to->value])->assertSessionHasErrors('action');

    expect($subOrder->fresh()->status)->toBe($from);
})->with([
    'placed to packed' => [SubOrderStatus::Placed, SubOrderStatus::Packed],
    'shipped to delivered' => [SubOrderStatus::Shipped, SubOrderStatus::Delivered],
    'placed to cancelled' => [SubOrderStatus::Placed, SubOrderStatus::Cancelled],
]);

it('cancels with a reason, puts the stock back and records a seller strike', function () {
    $listing = sellableListing('100.00', stock: 5, vendor: $this->vendor);
    $subOrder = subOrderFor($this->vendor, SubOrderStatus::Confirmed, quantity: 2, listing: $listing);
    expect(app(InventoryLedger::class)->available($listing))->toBe(3);

    $this->post(onPanel('partner', "orders/{$subOrder->id}/cancel"), ['reason' => 'Item damaged in our store'])->assertSessionHasNoErrors();

    $strike = VendorStrike::query()->sole();
    expect($subOrder->fresh()->status)->toBe(SubOrderStatus::Cancelled)
        ->and($subOrder->statusHistory()->get()->last()->reason)->toBe('Item damaged in our store')
        ->and(app(InventoryLedger::class)->available($listing))->toBe(5)
        ->and($strike->vendor_id)->toBe($this->vendor->id)
        ->and($strike->sub_order_id)->toBe($subOrder->id)
        ->and($strike->points)->toBe(5)
        ->and($strike->actor_user_id)->toBe($this->seller->id)
        ->and($subOrder->order->fresh()->status)->toBe(OrderStatus::Cancelled);

    $this->get(onPanel('partner'))->assertSee('95 / 100');
});

it('needs a reason to cancel', function () {
    $subOrder = subOrderFor($this->vendor);

    $this->post(onPanel('partner', "orders/{$subOrder->id}/cancel"), ['reason' => ''])->assertSessionHasErrors('reason');

    expect($subOrder->fresh()->status)->toBe(SubOrderStatus::Placed)
        ->and(VendorStrike::query()->count())->toBe(0);
});

it('cannot cancel a delivered sub-order', function () {
    $subOrder = subOrderFor($this->vendor, SubOrderStatus::Delivered);

    $this->post(onPanel('partner', "orders/{$subOrder->id}/cancel"), ['reason' => 'Changed our mind'])
        ->assertSessionHasErrors(['action' => 'This order can no longer be cancelled.']);

    expect(VendorStrike::query()->count())->toBe(0);
});

it('prints a packing slip for the sub-order', function () {
    $subOrder = subOrderFor($this->vendor, quantity: 3);

    $this->get(onPanel('partner', "orders/{$subOrder->id}/packing-slip"))
        ->assertOk()
        ->assertSee('Packing slip')
        ->assertSee($subOrder->number)
        ->assertSee('Sara Ahmed')
        ->assertSee('Total units: 3')
        ->assertSee('window.print()', false);
});
