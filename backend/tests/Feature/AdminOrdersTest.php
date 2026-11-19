<?php

use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Vendors\Models\Vendor;
use App\Domains\Vendors\Models\VendorStrike;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->actAsCountry('uae');
    $this->vendor = Vendor::factory()->create(['store_name' => 'SoundMax Official']);
});

it('lists the selected country\'s orders and filters by status', function () {
    $placed = subOrderFor($this->vendor)->order;
    $shipped = subOrderFor($this->vendor, SubOrderStatus::Shipped)->order;
    $shipped->forceFill(['status' => OrderStatus::Shipped])->save();
    $this->actAsCountry('omn');
    $omanOrder = subOrderFor(Vendor::factory()->create())->order;

    $this->actingAs(adminAccount())->get(onPanel('admin', 'orders?country=uae'))
        ->assertOk()
        ->assertSee($placed->number)
        ->assertSee($shipped->number)
        ->assertDontSee($omanOrder->number);
    $this->get(onPanel('admin', 'orders?status=shipped'))->assertSee($shipped->number)->assertDontSee($placed->number);
    $this->get(onPanel('admin', 'orders?country=omn'))->assertSee($omanOrder->number)->assertDontSee($placed->number);
});

it('filters by placement date in Oman time', function () {
    $lateNight = subOrderFor($this->vendor, placedAt: CarbonImmutable::parse('2026-11-13 21:30', 'UTC'))->order;
    $evening = subOrderFor($this->vendor, placedAt: CarbonImmutable::parse('2026-11-13 19:30', 'UTC'))->order;

    $this->actingAs(adminAccount())->get(onPanel('admin', 'orders?from=2026-11-14&to=2026-11-14'))
        ->assertOk()
        ->assertSee($lateNight->number)
        ->assertSee('14 Nov 2026, 01:30')
        ->assertDontSee($evening->number);
    $this->get(onPanel('admin', 'orders?to=2026-11-13'))->assertSee($evening->number)->assertDontSee($lateNight->number);
    $this->get(onPanel('admin', 'orders?from=2026-11-14&to=2026-11-13'))->assertSessionHasErrors('to');
});

it('shows the order with each sub-order and its timeline in Oman time', function () {
    $subOrder = subOrderFor($this->vendor, placedAt: CarbonImmutable::parse('2026-11-14 05:12', 'UTC'));

    $this->actingAs(adminAccount())->get(onPanel('admin', "orders/{$subOrder->order_id}"))
        ->assertOk()
        ->assertSee($subOrder->number)
        ->assertSee('SoundMax Official')
        ->assertSee('Placed 14 Nov 2026, 09:12 AE (09:12 Oman)')
        ->assertSee('+971500000000')
        ->assertSeeInOrder(['data-state="current"', 'Placed', 'Oman', 'data-state="pending"', 'Confirmed'], false)
        ->assertSee('Force-cancel');
});

it('moves a sub-order to an allowed next status with a reason', function () {
    $subOrder = subOrderFor($this->vendor, SubOrderStatus::OutForDelivery);
    $admin = adminAccount('uae', 'operations');

    $this->actingAs($admin)->post(onPanel('admin', "sub-orders/{$subOrder->id}/status"), ['to' => 'delivered', 'reason' => 'Customer confirmed by phone'])
        ->assertSessionHasNoErrors();

    $change = $subOrder->statusHistory()->get()->last();
    expect($subOrder->fresh()->status)->toBe(SubOrderStatus::Delivered)
        ->and($subOrder->fresh()->delivered_at)->not->toBeNull()
        ->and($change->actor_type)->toBe(ActorType::Admin)
        ->and($change->actor_user_id)->toBe($admin->id)
        ->and($change->reason)->toBe('Customer confirmed by phone')
        ->and($subOrder->order->fresh()->status)->toBe(OrderStatus::Delivered);
});

it('refuses admin moves the lifecycle does not allow or without a reason', function (SubOrderStatus $from, array $payload, string $error) {
    $subOrder = subOrderFor($this->vendor, $from);

    $this->actingAs(adminAccount())->post(onPanel('admin', "sub-orders/{$subOrder->id}/status"), $payload)->assertSessionHasErrors($error);

    expect($subOrder->fresh()->status)->toBe($from);
})->with([
    'skip ahead' => [SubOrderStatus::Placed, ['to' => 'delivered', 'reason' => 'Skipping ahead'], 'action'],
    'no reason' => [SubOrderStatus::Placed, ['to' => 'confirmed'], 'reason'],
    'cancel through the move form' => [SubOrderStatus::Placed, ['to' => 'cancelled', 'reason' => 'Use the cancel form'], 'to'],
    'from a final status' => [SubOrderStatus::Completed, ['to' => 'returned', 'reason' => 'Too late now'], 'action'],
]);

it('force-cancels a shipped sub-order, returns the stock and adds no seller strike', function () {
    $listing = sellableListing('100.00', stock: 4, vendor: $this->vendor);
    $subOrder = subOrderFor($this->vendor, SubOrderStatus::Shipped, quantity: 2, listing: $listing);

    $this->actingAs(adminAccount())->post(onPanel('admin', "sub-orders/{$subOrder->id}/cancel"), ['reason' => 'Fraud flagged by payments'])
        ->assertSessionHasNoErrors();

    expect($subOrder->fresh()->status)->toBe(SubOrderStatus::Cancelled)
        ->and($subOrder->statusHistory()->get()->last()->actor_type)->toBe(ActorType::Admin)
        ->and(app(InventoryLedger::class)->available($listing))->toBe(4)
        ->and(VendorStrike::query()->count())->toBe(0);
});

it('needs a reason to force-cancel and refuses cancelling a completed sub-order (a delivered one can be force-cancelled, QC-034)', function () {
    $placed = subOrderFor($this->vendor);
    $completed = subOrderFor($this->vendor, SubOrderStatus::Completed);
    $delivered = subOrderFor($this->vendor, SubOrderStatus::Delivered);

    $this->actingAs(adminAccount())->post(onPanel('admin', "sub-orders/{$placed->id}/cancel"))->assertSessionHasErrors('reason');
    $this->post(onPanel('admin', "sub-orders/{$completed->id}/cancel"), ['reason' => 'Customer complained'])->assertSessionHasErrors('action');
    $this->post(onPanel('admin', "sub-orders/{$delivered->id}/cancel"), ['reason' => 'Counterfeit item reported'])->assertSessionHasNoErrors();

    expect($placed->fresh()->status)->toBe(SubOrderStatus::Placed)
        ->and($completed->fresh()->status)->toBe(SubOrderStatus::Completed)
        ->and($delivered->fresh()->status)->toBe(SubOrderStatus::Cancelled);
});

it('applies the admin role permissions to order screens', function () {
    $subOrder = subOrderFor($this->vendor);
    $finance = adminAccount('uae', 'finance');

    $this->actingAs($finance)->get(onPanel('admin', 'orders'))->assertOk();
    $this->get(onPanel('admin', "orders/{$subOrder->order_id}"))->assertOk()->assertDontSee('Force-cancel');
    $this->post(onPanel('admin', "sub-orders/{$subOrder->id}/status"), ['to' => 'confirmed', 'reason' => 'Finance tries'])->assertForbidden();
    $this->post(onPanel('admin', "sub-orders/{$subOrder->id}/cancel"), ['reason' => 'Finance tries'])->assertForbidden();

    $this->actingAs(adminAccount('uae', 'marketing'))->get(onPanel('admin', 'orders'))->assertForbidden();

    $this->actAsCountry('uae');
    expect($subOrder->fresh()->status)->toBe(SubOrderStatus::Placed);
});

it('keeps a country admin out of other countries\' orders', function () {
    $this->actAsCountry('omn');
    $omanOrder = subOrderFor(Vendor::factory()->create())->order;
    $uaeOps = adminAccount('uae', 'operations');

    $this->actingAs($uaeOps)->get(onPanel('admin', 'orders?country=omn'))->assertOk()->assertDontSee($omanOrder->number)->assertSee('United Arab Emirates');
    $this->post(onPanel('admin', 'country'), ['country' => 'omn'])->assertSessionHasErrors('country');
});

it('lets a super admin switch the admin panel country', function () {
    $this->actingAs(adminAccount())->post(onPanel('admin', 'country'), ['country' => 'omn'])->assertRedirect();

    $this->get(onPanel('admin', 'orders'))->assertOk()->assertSee('Oman ·');
});
