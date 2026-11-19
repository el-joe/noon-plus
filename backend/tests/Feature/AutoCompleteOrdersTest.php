<?php

use App\Domains\Catalog\Models\Category;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Listings\Models\Listing;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Orders\Support\SubOrderStateMachine;
use App\Domains\Settings\SettingsService;
use App\Domains\Vendors\Models\Vendor;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->actAsCountry('uae');
    $this->travelTo('2026-11-02 10:00:00');
});

/**
 * Places a one-shipment order for the listings and walks it to delivered now.
 */
function deliveredShipment(Listing ...$listings): SubOrder
{
    $market = app(CountryContext::class)->current()->marketSlug('en');
    [, $token] = customerWithToken();

    foreach ($listings as $listing) {
        test()->withToken($token)->putJson("/api/v1/{$market}/cart/items", ['listing_id' => $listing->id, 'quantity' => 1])->assertOk();
    }

    $number = test()->withToken($token)->postJson("/api/v1/{$market}/orders", ['payment_method' => 'cod', 'address' => ['name' => 'Sara', 'city' => 'Dubai', 'area' => 'Marina', 'street' => 'Gate 2']])
        ->assertCreated()
        ->json('data.number');
    app('auth')->forgetGuards();
    $subOrder = Order::query()->where('number', $number)->sole()->subOrders()->sole();

    foreach ([SubOrderStatus::Confirmed, SubOrderStatus::Processing, SubOrderStatus::Packed, SubOrderStatus::Shipped, SubOrderStatus::Delivered] as $status) {
        app(SubOrderStateMachine::class)->transition($subOrder, $status, ActorType::Admin, 1);
    }

    return $subOrder;
}

function listingInCategory(Category $category, ?Vendor $vendor = null): Listing
{
    $listing = sellableListing('50.00', vendor: $vendor);
    $listing->variant->product->forceFill(['category_id' => $category->id])->save();

    return $listing;
}

it('completes a delivered shipment exactly when the default 14-day return window has passed', function () {
    $subOrder = deliveredShipment(sellableListing('50.00'));

    $this->travelTo('2026-11-16 09:59:59');
    $this->artisan('nawy:orders:auto-complete')->assertSuccessful();
    expect($subOrder->fresh()->status)->toBe(SubOrderStatus::Delivered);

    $this->travelTo('2026-11-16 10:00:00');
    $this->artisan('nawy:orders:auto-complete')->assertSuccessful();

    $history = $subOrder->statusHistory()->get()->last();
    expect($subOrder->fresh()->status)->toBe(SubOrderStatus::Completed)
        ->and($history->actor_type)->toBe(ActorType::System)
        ->and($history->actor_user_id)->toBeNull()
        ->and($history->reason)->toBe('Return window of 14 days passed')
        ->and($subOrder->order->fresh()->status)->toBe(OrderStatus::Completed);
});

it('uses the category return window, inherited from the parent when the category has none', function (?int $parentDays, ?int $childDays, string $stillDelivered, string $completed) {
    $parent = Category::factory()->create(['return_window_days' => $parentDays]);
    $subOrder = deliveredShipment(listingInCategory(Category::factory()->childOf($parent)->create(['return_window_days' => $childDays])));

    $this->travelTo($stillDelivered);
    $this->artisan('nawy:orders:auto-complete');
    expect($subOrder->fresh()->status)->toBe(SubOrderStatus::Delivered);

    $this->travelTo($completed);
    $this->artisan('nawy:orders:auto-complete');
    expect($subOrder->fresh()->status)->toBe(SubOrderStatus::Completed);
})->with([
    'own 30 days' => [7, 30, '2026-12-02 09:00:00', '2026-12-02 10:00:00'],
    'parent 7 days' => [7, null, '2026-11-09 09:00:00', '2026-11-09 10:00:00'],
]);

it('waits for the longest return window among the shipment\'s items', function () {
    $vendor = Vendor::factory()->create();
    $short = listingInCategory(Category::factory()->create(['return_window_days' => 3]), $vendor);
    $long = listingInCategory(Category::factory()->create(['return_window_days' => 10]), $vendor);
    $subOrder = deliveredShipment($short, $long);

    $this->travelTo('2026-11-05 10:00:00');
    $this->artisan('nawy:orders:auto-complete');
    expect($subOrder->fresh()->status)->toBe(SubOrderStatus::Delivered);

    $this->travelTo('2026-11-12 10:00:00');
    $this->artisan('nawy:orders:auto-complete');
    expect($subOrder->fresh()->status)->toBe(SubOrderStatus::Completed);
});

it('keeps the window promised at purchase even if the category changes later', function () {
    $category = Category::factory()->create(['return_window_days' => 5]);
    $subOrder = deliveredShipment(listingInCategory($category));
    $category->update(['return_window_days' => 30]);

    $this->travelTo('2026-11-07 10:00:00');
    $this->artisan('nawy:orders:auto-complete');

    expect($subOrder->fresh()->status)->toBe(SubOrderStatus::Completed);
});

it('uses the country return-window setting as the default', function () {
    app(SettingsService::class)->set('return_window_days', 3, $this->actAsCountry('uae'));
    $subOrder = deliveredShipment(sellableListing('50.00'));

    $this->travelTo('2026-11-05 10:00:00');
    $this->artisan('nawy:orders:auto-complete');

    expect($subOrder->fresh()->status)->toBe(SubOrderStatus::Completed);
});

it('leaves shipments that are not delivered alone and runs in every country', function () {
    $uaeShipment = deliveredShipment(sellableListing('50.00'));
    $this->actAsCountry('omn');
    $omanShipment = deliveredShipment(sellableListing('5.000'));
    app(SubOrderStateMachine::class)->transition($omanShipment, SubOrderStatus::Returned, ActorType::System);

    $this->travelTo('2026-12-01 10:00:00');
    $this->artisan('nawy:orders:auto-complete')->assertSuccessful();

    expect($omanShipment->fresh()->status)->toBe(SubOrderStatus::Returned);
    $this->actAsCountry('uae');
    expect($uaeShipment->fresh()->status)->toBe(SubOrderStatus::Completed);
});

it('is checked every 15 minutes (each country runs at its order.auto_complete_time, QC-038), and flash sales every 5 minutes', function () {
    $events = collect(app(Schedule::class)->events())->keyBy(fn (Event $event): string => str($event->command)->after("'artisan' ")->toString());

    expect($events['nawy:orders:auto-complete --scheduled']->expression)->toBe('*/15 * * * *')
        ->and($events['nawy:flash-sales:transition']->expression)->toBe('*/5 * * * *');
});
