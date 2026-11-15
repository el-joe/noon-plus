<?php

use App\Domains\FlashSales\Enums\FlashSaleEventStatus;
use App\Domains\FlashSales\Enums\FlashSaleSubmissionStatus;
use App\Domains\FlashSales\Exceptions\FlashSaleTransitionRefused;
use App\Domains\FlashSales\Models\FlashSaleEvent;
use App\Domains\FlashSales\Models\FlashSaleSubmission;
use App\Domains\FlashSales\Support\CreateFlashSaleEvent;
use App\Domains\FlashSales\Support\FlashSaleEventMachine;
use App\Domains\FlashSales\Support\FlashSaleSubmissionMachine;
use App\Domains\Listings\Models\Listing;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Orders\Support\SubOrderStateMachine;
use App\Domains\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->actAsCountry('uae');
});

/**
 * A deal on a listing inside an event that is running now (arranged directly).
 */
function runningDeal(Listing $listing, string $flashPrice, int $cap = 10, int $maxPerCustomer = 2, FlashSaleEventStatus $eventStatus = FlashSaleEventStatus::Live): FlashSaleSubmission
{
    $event = FlashSaleEvent::factory()->running()->inStatus($eventStatus)->create(['max_qty_per_customer' => $maxPerCustomer]);
    $submission = FlashSaleSubmission::query()->create([
        'flash_sale_event_id' => $event->id,
        'listing_id' => $listing->id,
        'vendor_id' => $listing->vendor_id,
        'original_price' => $listing->effectivePrice(),
        'flash_price' => $flashPrice,
        'quantity_cap' => $cap,
    ]);
    $submission->forceFill(['status' => $eventStatus === FlashSaleEventStatus::Live ? FlashSaleSubmissionStatus::Live : FlashSaleSubmissionStatus::Approved])->save();

    return $submission;
}

/**
 * @return array<string, mixed>
 */
function flashOrderAddress(): array
{
    return ['payment_method' => 'card', 'address' => ['name' => 'Sara', 'city' => 'Dubai', 'area' => 'Marina', 'street' => 'Gate 2']];
}

it('stores event times typed in the country\'s local time as UTC and defaults the per-customer maximum', function () {
    $this->actAsCountry('egy');

    $event = app(CreateFlashSaleEvent::class)->handle([
        'name' => 'White Friday',
        'starts_at' => '2026-11-27 22:00',
        'ends_at' => '2026-11-28 22:00',
        'submission_ends_at' => '2026-11-25 18:00',
    ], createdBy: 7);

    expect($event->fresh())
        ->status->toBe(FlashSaleEventStatus::Draft)
        ->starts_at->toDateTimeString()->toBe('2026-11-27 20:00:00')
        ->submission_ends_at->toDateTimeString()->toBe('2026-11-25 16:00:00')
        ->max_qty_per_customer->toBe(2)
        ->and($event->statusHistory()->sole()->actor_user_id)->toBe(7);
});

it('takes the per-customer maximum from the country setting', function () {
    app(SettingsService::class)->set('flash_sale.max_qty_per_customer', 5, $this->actAsCountry('uae'));

    $event = app(CreateFlashSaleEvent::class)->handle(['name' => 'Mega Deals', 'starts_at' => '2026-12-01 10:00', 'ends_at' => '2026-12-01 22:00']);

    expect($event->max_qty_per_customer)->toBe(5);
});

it('refuses an event that ends before it starts or closes submissions after the start', function (array $times) {
    app(CreateFlashSaleEvent::class)->handle(['name' => 'Broken'] + $times);
})->with([
    'end before start' => [['starts_at' => '2026-12-01 10:00', 'ends_at' => '2026-12-01 09:00']],
    'submissions close after start' => [['starts_at' => '2026-12-01 10:00', 'ends_at' => '2026-12-01 22:00', 'submission_ends_at' => '2026-12-01 11:00']],
])->throws(FlashSaleTransitionRefused::class);

it('accepts a seller submission below the current price within stock, while submissions are open', function () {
    $event = FlashSaleEvent::factory()->inStatus(FlashSaleEventStatus::SubmissionOpen)->create();
    $listing = sellableListing('100.00', stock: 5);

    $submission = app(FlashSaleSubmissionMachine::class)->submit($event, $listing, '79.00', 5, 3);

    expect($submission)
        ->status->toBe(FlashSaleSubmissionStatus::Submitted)
        ->original_price->toBe('100.0000')
        ->and($submission->statusHistory()->pluck('to_status')->map->value->all())->toBe(['draft', 'submitted']);
});

it('refuses submissions that break the rules', function (string $flashPrice, int $cap, FlashSaleEventStatus $eventStatus) {
    $event = FlashSaleEvent::factory()->inStatus($eventStatus)->create();

    app(FlashSaleSubmissionMachine::class)->submit($event, sellableListing('100.00', stock: 5), $flashPrice, $cap);
})->with([
    'flash price not below the price' => ['100.00', 1, FlashSaleEventStatus::SubmissionOpen],
    'cap above stock' => ['80.00', 6, FlashSaleEventStatus::SubmissionOpen],
    'zero cap' => ['80.00', 0, FlashSaleEventStatus::SubmissionOpen],
    'submissions closed' => ['80.00', 1, FlashSaleEventStatus::UnderReview],
])->throws(FlashSaleTransitionRefused::class);

it('needs a reason to reject a submission', function () {
    $event = FlashSaleEvent::factory()->inStatus(FlashSaleEventStatus::SubmissionOpen)->create();
    $submission = app(FlashSaleSubmissionMachine::class)->submit($event, sellableListing('100.00'), '80.00', 1);
    $submission->forceFill(['status' => FlashSaleSubmissionStatus::UnderReview])->save();

    app(FlashSaleSubmissionMachine::class)->transition($submission, FlashSaleSubmissionStatus::Rejected, ActorType::Admin, 1);
})->throws(FlashSaleTransitionRefused::class);

it('moves an event through its lifecycle on the 5-minute schedule', function () {
    $this->travelTo('2026-11-20 06:00:00');
    $event = app(CreateFlashSaleEvent::class)->handle([
        'name' => 'Weekend Deals',
        'submission_starts_at' => '2026-11-20 12:00',
        'submission_ends_at' => '2026-11-21 12:00',
        'starts_at' => '2026-11-22 10:00',
        'ends_at' => '2026-11-22 22:00',
    ]);
    $tick = fn () => $this->artisan('nawy:flash-sales:transition')->assertSuccessful();

    $tick();
    expect($event->fresh()->status)->toBe(FlashSaleEventStatus::Draft);

    $this->travelTo('2026-11-20 08:00:00');
    $tick();
    expect($event->fresh()->status)->toBe(FlashSaleEventStatus::SubmissionOpen);

    $machine = app(FlashSaleSubmissionMachine::class);
    $approved = $machine->submit($event->fresh(), sellableListing('100.00'), '70.00', 5);
    $unreviewed = $machine->submit($event->fresh(), sellableListing('50.00'), '40.00', 5);

    $this->travelTo('2026-11-21 08:00:00');
    $tick();
    expect($event->fresh()->status)->toBe(FlashSaleEventStatus::UnderReview)
        ->and($approved->fresh()->status)->toBe(FlashSaleSubmissionStatus::UnderReview);

    $machine->transition($approved->fresh(), FlashSaleSubmissionStatus::Approved, ActorType::Admin, 1);
    app(FlashSaleEventMachine::class)->transition($event->fresh(), FlashSaleEventStatus::Approved, ActorType::Admin, 1);

    $this->travelTo('2026-11-22 06:00:00');
    $tick();
    expect($event->fresh()->status)->toBe(FlashSaleEventStatus::Live)
        ->and($approved->fresh()->status)->toBe(FlashSaleSubmissionStatus::Live)
        ->and($unreviewed->fresh()->status)->toBe(FlashSaleSubmissionStatus::Rejected);

    $this->travelTo('2026-11-22 18:00:00');
    $tick();
    expect($event->fresh()->status)->toBe(FlashSaleEventStatus::Ended)
        ->and($approved->fresh()->status)->toBe(FlashSaleSubmissionStatus::Ended)
        ->and($event->statusHistory()->pluck('actor_type')->map->value->all())
        ->toBe(['admin', 'system', 'system', 'system', 'admin', 'system', 'system']);
});

it('withdraws the submissions of an event cancelled before going live, and cannot cancel a live one', function () {
    $event = FlashSaleEvent::factory()->inStatus(FlashSaleEventStatus::SubmissionOpen)->create();
    $submission = app(FlashSaleSubmissionMachine::class)->submit($event, sellableListing('100.00'), '80.00', 1);
    $machine = app(FlashSaleEventMachine::class);

    $machine->transition($event, FlashSaleEventStatus::Cancelled, ActorType::Admin, 1, 'Supplier issue');
    expect($submission->fresh()->status)->toBe(FlashSaleSubmissionStatus::Withdrawn);

    $machine->transition(FlashSaleEvent::factory()->running()->create(), FlashSaleEventStatus::Cancelled, ActorType::Admin, 1);
})->throws(FlashSaleTransitionRefused::class);

it('charges the flash price only from the start time up to the end time', function () {
    $listing = sellableListing('100.00');
    $deal = runningDeal($listing, '79.00', eventStatus: FlashSaleEventStatus::Approved);
    $deal->event->update(['starts_at' => now()->addHour(), 'ends_at' => now()->addHours(3)]);
    $token = $this->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1])->headers->get('X-Cart-Token');
    $price = fn (): string => $this->withHeader('X-Cart-Token', $token)->getJson('/api/v1/uae-en/cart')->json('data.shipments.0.items.0.unit_price.amount');

    expect($price())->toBe('100.00');

    $this->travel(1)->hours();
    expect($price())->toBe('79.00');

    $this->travel(2)->hours();
    expect($price())->toBe('100.00');
});

it('shows the deal price, regular price and end time in the cart', function () {
    $listing = sellableListing('100.00');
    $deal = runningDeal($listing, '79.00');

    $this->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 2])
        ->assertJsonPath('data.shipments.0.items.0.unit_price.amount', '79.00')
        ->assertJsonPath('data.shipments.0.items.0.regular_price.amount', '100.00')
        ->assertJsonPath('data.shipments.0.items.0.flash_sale.ends_at', $deal->event->ends_at->toIso8601String())
        ->assertJsonPath('data.totals.subtotal.amount', '158.00');
});

it('enforces the per-customer maximum across orders, freeing it again when a shipment is cancelled', function () {
    [, $token] = customerWithToken();
    $listing = sellableListing('100.00', stock: 10);
    runningDeal($listing, '79.00', maxPerCustomer: 2);

    $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 3])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'flash_sale_customer_limit');

    $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 2])->assertOk();
    $this->withToken($token)->postJson('/api/v1/uae-en/orders', flashOrderAddress())->assertCreated()->assertJsonPath('data.totals.subtotal.amount', '158.00');

    $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'flash_sale_customer_limit');

    app(SubOrderStateMachine::class)->transition(Order::query()->sole()->subOrders()->sole(), SubOrderStatus::Cancelled, ActorType::Customer, null);

    $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 2])->assertOk();
});

it('caps the units sold at the flash price, marks the deal sold out and reverts the price', function () {
    [, $first] = customerWithToken();
    [, $second] = customerWithToken();
    $listing = sellableListing('100.00', stock: 10);
    $deal = runningDeal($listing, '79.00', cap: 3);

    $this->withToken($first)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 2]);
    $this->withToken($first)->postJson('/api/v1/uae-en/orders', flashOrderAddress())->assertCreated();
    expect($deal->fresh()->sold_quantity)->toBe(2);

    app('auth')->forgetGuards();
    $this->withToken($second)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 2])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'flash_sale_sold_out');
    $this->withToken($second)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1])->assertOk();
    $this->withToken($second)->postJson('/api/v1/uae-en/orders', flashOrderAddress())->assertCreated()->assertJsonPath('data.totals.subtotal.amount', '79.00');

    expect($deal->fresh())
        ->sold_quantity->toBe(3)
        ->status->toBe(FlashSaleSubmissionStatus::SoldOut)
        ->and((int) OrderItem::query()->whereNotNull('flash_sale_submission_id')->sum('quantity'))->toBe(3);

    $this->withToken($second)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1])
        ->assertOk()
        ->assertJsonPath('data.shipments.0.items.0.unit_price.amount', '100.00');
});

it('refuses checkout with 409 when the cart went over a deal limit after it was added', function () {
    [, $token] = customerWithToken();
    $listing = sellableListing('100.00', stock: 10);
    $deal = runningDeal($listing, '79.00', maxPerCustomer: 3);
    $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 3])->assertOk();

    $deal->event->update(['max_qty_per_customer' => 1]);

    $this->withToken($token)->getJson('/api/v1/uae-en/cart')
        ->assertJsonPath('data.shipments.0.items.0.unit_price.amount', '100.00')
        ->assertJsonCount(1, 'data.warnings');
    $this->withToken($token)->postJson('/api/v1/uae-en/orders', flashOrderAddress())
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'flash_sale_limit');
    expect(Order::query()->count())->toBe(0);
});

it('rounds flash prices to 3 decimals in Kuwait', function () {
    $this->actAsCountry('kwt');
    $listing = sellableListing('9.750');
    runningDeal($listing, '7.4995');

    $this->putJson('/api/v1/kwt-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 2])
        ->assertJsonPath('data.shipments.0.items.0.unit_price', ['amount' => '7.500', 'currency' => 'KWD'])
        ->assertJsonPath('data.totals.subtotal.amount', '15.000');
});

it('lists running and upcoming approved flash sales for the Deals page, country by country', function () {
    $listing = sellableListing('100.00');
    runningDeal($listing, '79.00', cap: 4)->forceFill(['sold_quantity' => 1])->save();
    FlashSaleEvent::factory()->inStatus(FlashSaleEventStatus::Approved)->create(['name' => 'Tomorrow']);
    FlashSaleEvent::factory()->inStatus(FlashSaleEventStatus::UnderReview)->create(['name' => 'Hidden']);

    $this->getJson('/api/v1/uae-en/flash-sales')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.state', 'live')
        ->assertJsonPath('data.0.deals.0.price.amount', '79.00')
        ->assertJsonPath('data.0.deals.0.was_price.amount', '100.00')
        ->assertJsonPath('data.0.deals.0.claimed_percent', 25)
        ->assertJsonPath('data.1.name', 'Tomorrow')
        ->assertJsonPath('data.1.state', 'upcoming');

    $this->getJson('/api/v1/ksa-en/flash-sales')->assertOk()->assertJsonCount(0, 'data');
});

it('shows the flash price with the regular one as "was" on the product page', function () {
    $listing = sellableListing('100.00');
    runningDeal($listing, '79.00', cap: 5, maxPerCustomer: 2);

    $this->getJson('/api/v1/uae-en/products/'.$listing->variant->product->slug)
        ->assertJsonPath('data.buy_box.price.amount', '79.00')
        ->assertJsonPath('data.buy_box.was_price.amount', '100.00')
        ->assertJsonPath('data.buy_box.flash_sale.remaining', 5)
        ->assertJsonPath('data.buy_box.flash_sale.max_per_customer', 2);
});
