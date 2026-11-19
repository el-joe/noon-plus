<?php

use App\Domains\Customers\Models\CustomerNotification;
use App\Domains\Delivery\Models\Shipment;
use App\Domains\Delivery\Support\DeliveryDispatch;
use App\Domains\Finance\Models\SubOrderSettlement;
use App\Domains\Finance\Models\VendorBalanceItem;
use App\Domains\Finance\Support\SettlementCalculator;
use App\Domains\Fulfilment\Enums\InboundStatus;
use App\Domains\Fulfilment\Enums\RemovalStatus;
use App\Domains\Fulfilment\Enums\RemovalType;
use App\Domains\Fulfilment\Enums\ReservationStatus;
use App\Domains\Fulfilment\Enums\StockBucket;
use App\Domains\Fulfilment\Enums\TransferStatus;
use App\Domains\Fulfilment\Models\FbnReservation;
use App\Domains\Fulfilment\Models\InboundShipment;
use App\Domains\Fulfilment\Models\StorageInvoice;
use App\Domains\Fulfilment\Models\StorageSnapshot;
use App\Domains\Fulfilment\Models\Warehouse;
use App\Domains\Fulfilment\Models\WarehouseStock;
use App\Domains\Fulfilment\Models\WarehouseStockMovement;
use App\Domains\Fulfilment\Support\FbnEnrolment;
use App\Domains\Fulfilment\Support\FbnFees;
use App\Domains\Fulfilment\Support\FbnOrders;
use App\Domains\Fulfilment\Support\InboundShipments;
use App\Domains\Fulfilment\Support\RemovalOrders;
use App\Domains\Fulfilment\Support\StockTransfers;
use App\Domains\Fulfilment\Support\WarehouseStockService;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Exceptions\SubOrderTransitionRefused;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Orders\Support\PlaceOrder;
use App\Domains\Orders\Support\SellerFulfilment;
use App\Domains\Orders\Support\SubOrderStateMachine;
use App\Domains\Panels\Navigation\PanelNavigation;
use App\Domains\Panels\Support\Panel;
use App\Domains\Settings\SettingsService;
use App\Domains\Shipping\Support\ShipmentQuoter;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\StateMachine\StateTransition;
use App\Shared\StateMachine\TransitionRefused;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
    Notification::fake();
    $this->stock = app(WarehouseStockService::class);
    config(['nawy.fbn.fees' => sampleFbnFees()]);
});

/** The help-centre fee schedule (AED), used where a test needs non-zero FBN fees (defaults are 0 per F11). */
function sampleFbnFees(): array
{
    return [
        'outbound_price_threshold' => '25',       // ASP band: ≤ threshold uses `low`, above uses `high`
        'outbound_tiers' => [                     // billable weight ≤ max_grams → fee per unit
            ['max_grams' => 100, 'low' => '5.5', 'high' => '7.5'],
            ['max_grams' => 250, 'low' => '6.0', 'high' => '8.0'],
            ['max_grams' => 500, 'low' => '6.0', 'high' => '8.0'],
            ['max_grams' => 1000, 'low' => '8.0', 'high' => '10.0'],
            ['max_grams' => 1500, 'low' => '8.5', 'high' => '10.5'],
            ['max_grams' => 2000, 'low' => '9.0', 'high' => '11.0'],
            ['max_grams' => 3000, 'low' => '10.0', 'high' => '12.0'],
            ['max_grams' => 20000, 'low' => '33.0', 'high' => '35.0'],
        ],
        'outbound_extra_per_kg' => '1.0',         // above the last tier, per started kg
        'storage_per_cubic_foot' => '1.5',        // per cubic foot per month
        'long_term_per_cubic_foot' => '25',       // per cubic foot per month, units older than long_term_days
        'unsellable_per_cubic_foot' => '12',      // per cubic foot per month, damaged older than unsellable_days
        'removal_minimum' => '15',                // per removal order
        'removal_per_unit' => '0.3',
        'removal_per_kg' => '1.0',                // billable kg (actual or L×W×H/5000, the higher)
        'disposal_per_unit' => '0.3',
    ];
}

function fbnWarehouse(string $code = 'DXB-1'): Warehouse
{
    return Warehouse::query()->create(['code' => $code, 'name_en' => "Warehouse {$code}", 'name_ar' => "مستودع {$code}", 'city' => 'Dubai', 'address' => 'Jebel Ali', 'is_active' => true]);
}

/**
 * An active FBN listing with `$units` received into the warehouse.
 */
function fbnListing(Warehouse $warehouse, int $units = 10, ?Vendor $vendor = null, string $price = '100.00', int $grams = 400): Listing
{
    $listing = sellableListing($price, stock: 0, vendor: $vendor);
    $listing->variant->forceFill(['weight_grams' => $grams])->save();
    app(FbnEnrolment::class)->enrol($listing, null);

    if ($units > 0) {
        app(WarehouseStockService::class)->receive($warehouse, $listing->refresh()->load('variant'), $units, 0, 'A-01', 'test', 1, null);
    }

    return $listing->refresh()->load('variant.product');
}

function sellable(Listing $listing): int
{
    return app(InventoryLedger::class)->available($listing);
}

function warehouseRow(Warehouse $warehouse, Listing $listing): WarehouseStock
{
    return WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('listing_id', $listing->id)->sole();
}

describe('stock math', function () {
    it('keeps the sellable quantity equal to the warehouse available units through receive, sale, cancel and dispatch', function () {
        $warehouse = fbnWarehouse();
        $listing = fbnListing($warehouse, 10);

        expect(sellable($listing))->toBe(10)->and($listing->status)->toBe(ListingStatus::Active);

        $subOrder = subOrderFor($listing->vendor, SubOrderStatus::Placed, 3, $listing);
        $row = warehouseRow($warehouse, $listing);

        // subOrderFor takes units without an order item: they leave the free stock.
        expect($row->on_hand)->toBe(7)->and($row->reserved)->toBe(0)->and(sellable($listing))->toBe(7);

        app(SubOrderStateMachine::class)->transition($subOrder, SubOrderStatus::Cancelled, ActorType::Admin, null, 'test');

        expect(warehouseRow($warehouse, $listing)->on_hand)->toBe(10)->and(sellable($listing))->toBe(10);
    });

    it('reserves warehouse units at checkout, deducts them on dispatch and releases them on cancellation', function () {
        $warehouse = fbnWarehouse();
        $listing = fbnListing($warehouse, 6);
        [$customer, $token] = customerWithToken();

        $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 2])->assertOk();
        $this->withToken($token)->postJson('/api/v1/uae-en/orders', [
            'payment_method' => 'cod',
            'address' => ['name' => 'Sara Ahmed', 'phone' => '+971500000000', 'city' => 'Dubai', 'area' => 'Marina', 'street' => 'Gate 2'],
        ])->assertCreated();

        $reservation = FbnReservation::query()->sole();
        $row = warehouseRow($warehouse, $listing);
        expect($reservation->status)->toBe(ReservationStatus::Reserved)->and($reservation->quantity)->toBe(2)
            ->and($row->on_hand)->toBe(6)->and($row->reserved)->toBe(2)->and($row->available())->toBe(4)->and(sellable($listing))->toBe(4);

        $subOrder = SubOrder::query()->findOrFail($reservation->sub_order_id);
        expect(app(FbnOrders::class)->isWarehouseFulfilled($subOrder))->toBeTrue()
            ->and(app(SellerFulfilment::class)->nextStepFor($subOrder))->toBeNull();
        expect(fn () => app(SellerFulfilment::class)->advance($subOrder, SubOrderStatus::Confirmed, $listing->vendor->owner_user_id))->toThrow(SubOrderTransitionRefused::class);

        foreach ([SubOrderStatus::Confirmed, SubOrderStatus::Processing, SubOrderStatus::Packed, SubOrderStatus::Shipped] as $step) {
            app(FbnOrders::class)->advance($subOrder->refresh(), $step, 1);
        }

        $row->refresh();
        expect($row->on_hand)->toBe(4)->and($row->reserved)->toBe(0)->and(sellable($listing))->toBe(4)
            ->and($reservation->refresh()->status)->toBe(ReservationStatus::Dispatched)
            ->and(Shipment::query()->where('sub_order_id', $subOrder->id)->exists())->toBeTrue();

        // Cancelled in transit: the parcel goes back on the shelf.
        app(SubOrderStateMachine::class)->transition($subOrder->refresh(), SubOrderStatus::Cancelled, ActorType::Admin, 1, 'Customer refused');

        expect($row->refresh()->on_hand)->toBe(6)->and(sellable($listing))->toBe(6)
            ->and($reservation->refresh()->status)->toBe(ReservationStatus::Returned);
    });

    it('releases a reservation still in the warehouse when the order is cancelled', function () {
        $warehouse = fbnWarehouse();
        $listing = fbnListing($warehouse, 5);
        [$customer, $token] = customerWithToken();
        $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 5])->assertOk();
        $this->withToken($token)->postJson('/api/v1/uae-en/orders', [
            'payment_method' => 'cod',
            'address' => ['name' => 'Sara Ahmed', 'phone' => '+971500000000', 'city' => 'Dubai', 'area' => 'Marina', 'street' => 'Gate 2'],
        ])->assertCreated();

        expect($listing->refresh()->status)->toBe(ListingStatus::OutOfStock);

        $reservation = FbnReservation::query()->sole();
        app(SubOrderStateMachine::class)->transition(SubOrder::query()->findOrFail($reservation->sub_order_id), SubOrderStatus::Cancelled, ActorType::Customer, $customer->id);

        expect($reservation->refresh()->status)->toBe(ReservationStatus::Released)
            ->and(warehouseRow($warehouse, $listing)->reserved)->toBe(0)
            ->and(sellable($listing))->toBe(5)->and($listing->refresh()->status)->toBe(ListingStatus::Active);
    });

    it('never lets a counter or the available units go negative', function () {
        $warehouse = fbnWarehouse();
        $listing = fbnListing($warehouse, 3);

        expect(fn () => $this->stock->adjust($warehouse, $listing, StockBucket::OnHand, -4, 'lost', 'cycle count', 1))->toThrow(TransitionRefused::class)
            ->and(fn () => $this->stock->adjust($warehouse, $listing, StockBucket::Damaged, -1, 'correction', 'oops', 1))->toThrow(TransitionRefused::class)
            ->and(fn () => $this->stock->adjust($warehouse, $listing, StockBucket::Reserved, 1, 'correction', 'no', 1))->toThrow(TransitionRefused::class);

        $this->stock->markDamaged($warehouse, $listing, 1, 'crushed box', 7);
        $row = warehouseRow($warehouse, $listing);
        expect($row->on_hand)->toBe(2)->and($row->damaged)->toBe(1)->and(sellable($listing))->toBe(2)
            ->and(WarehouseStockMovement::query()->where('reason', 'marked_damaged')->where('actor_user_id', 7)->count())->toBe(2);
    });

    it('keeps sellers from changing FBN stock by hand and FBN listings from leaving with stock inside', function () {
        $warehouse = fbnWarehouse();
        [$seller, $vendor] = sellerAccount();
        $listing = fbnListing($warehouse, 4, $vendor);

        $this->actingAs($seller)->post(onPanel('partner', "products/{$listing->id}/stock"), ['quantity' => 5, 'reason' => 'restock'])->assertSessionHasErrors('quantity');
        expect(sellable($listing))->toBe(4)
            ->and(fn () => app(FbnEnrolment::class)->leave($listing, $seller->id))->toThrow(TransitionRefused::class);
    });

    it('takes the seller declared stock off sale on enrolment', function () {
        $listing = sellableListing('50.00', stock: 7);
        app(FbnEnrolment::class)->enrol($listing, null);

        expect($listing->refresh()->fulfilment)->toBe('fbn')->and(sellable($listing))->toBe(0)->and($listing->status)->toBe(ListingStatus::OutOfStock);
    });
});

describe('inbound shipments', function () {
    it('moves an inbound plan through its lifecycle with history and puts received units on sale', function () {
        $warehouse = fbnWarehouse();
        [$seller, $vendor] = sellerAccount();
        $listing = fbnListing($warehouse, 0, $vendor);
        $inbound = app(InboundShipments::class);

        $shipment = $inbound->draft($vendor, $warehouse->id, [['listing_id' => $listing->id, 'quantity' => 10, 'cartons' => 2]], [], $seller->id);
        expect($shipment->status)->toBe(InboundStatus::Draft)->and($shipment->cartons)->toBe(2);

        $inbound->submit($shipment, $seller->id);
        expect($shipment->number)->toBe(sprintf('FBN-IN-AE-%06d', $shipment->id));
        expect(fn () => $inbound->startReceiving($shipment, 1))->toThrow(TransitionRefused::class);

        $inbound->markShipped($shipment, 'Aramex', 'AX123', $seller->id);
        $inbound->markArrived($shipment, 1);
        $inbound->startReceiving($shipment, 1);
        $line = $shipment->lines()->sole();
        $inbound->receiveLine($line, 7, 1, 'B-07', 1);

        expect(fn () => $inbound->receiveLine($line->refresh(), 3, 0, null, 1))->toThrow(TransitionRefused::class);

        $inbound->finishReceiving($shipment, 1);
        $inbound->close($shipment, 1);

        $row = warehouseRow($warehouse, $listing);
        expect($row->on_hand)->toBe(7)->and($row->damaged)->toBe(1)->and($row->bin_code)->toBe('B-07')
            ->and(sellable($listing))->toBe(7)->and($line->refresh()->shortQuantity())->toBe(2)
            ->and($shipment->refresh()->status)->toBe(InboundStatus::Closed)
            ->and(StateTransition::query()->where('subject_type', 'fbn_inbound_shipment')->where('subject_id', $shipment->id)->pluck('to_status')->all())
            ->toBe(['draft', 'submitted', 'in_transit', 'arrived', 'receiving', 'received', 'closed']);

        expect(CustomerNotification::query()->where('user_id', $seller->id)->where('type', 'fbn.inbound_received')->exists())->toBeTrue();
    });

    it('refuses plans with products not enrolled in FBN and cancels open plans', function () {
        $warehouse = fbnWarehouse();
        [$seller, $vendor] = sellerAccount();
        $fbm = sellableListing(stock: 3, vendor: $vendor);
        $inbound = app(InboundShipments::class);

        expect(fn () => $inbound->draft($vendor, $warehouse->id, [['listing_id' => $fbm->id, 'quantity' => 1]], [], $seller->id))->toThrow(TransitionRefused::class);

        $listing = fbnListing($warehouse, 0, $vendor);
        $shipment = $inbound->submit($inbound->draft($vendor, $warehouse->id, [['listing_id' => $listing->id, 'quantity' => 1]], [], $seller->id), $seller->id);
        $inbound->cancel($shipment, ActorType::Vendor, $seller->id);

        expect($shipment->status)->toBe(InboundStatus::Cancelled)
            ->and(fn () => $inbound->submit($shipment, $seller->id))->toThrow(TransitionRefused::class);
    });

    it('lets the seller create, submit and print labels for an inbound plan in Seller Center', function () {
        $warehouse = fbnWarehouse();
        [$seller, $vendor] = sellerAccount();
        $listing = fbnListing($warehouse, 2, $vendor);

        $this->actingAs($seller)->get(onPanel('partner', 'inventory'))->assertOk()->assertSee($listing->variant->sku)->assertSee(__('Low stock'));
        $this->get(onPanel('partner', 'inventory/inbound/create'))->assertOk()->assertSee($warehouse->code);
        $this->post(onPanel('partner', 'inventory/inbound'), [
            'warehouse_id' => $warehouse->id, 'intent' => 'submit', 'cartons' => 3,
            'lines' => [['listing_id' => $listing->id, 'quantity' => 12, 'cartons' => 3], ['listing_id' => '', 'quantity' => '']],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $shipment = InboundShipment::query()->sole();
        expect($shipment->status)->toBe(InboundStatus::Submitted);

        $this->get(onPanel('partner', "inventory/inbound/{$shipment->id}"))->assertOk()->assertSee($shipment->number);
        $this->get(onPanel('partner', "inventory/inbound/{$shipment->id}/labels"))->assertOk()->assertSee(__('Carton :number of :total', ['number' => 3, 'total' => 3]));
        $this->get(onPanel('partner', "inventory/inbound/{$shipment->id}/labels.pdf"))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->post(onPanel('partner', "inventory/inbound/{$shipment->id}/ship"), ['carrier_name' => 'Aramex', 'tracking_number' => 'AX1'])->assertSessionHasNoErrors();
        expect($shipment->refresh()->status)->toBe(InboundStatus::InTransit);

        [$otherSeller] = sellerAccount();
        $this->actingAs($otherSeller)->get(onPanel('partner', "inventory/inbound/{$shipment->id}"))->assertNotFound();
    });

    it('lets warehouse staff receive in the admin panel and keeps other roles out', function () {
        $warehouse = fbnWarehouse();
        [$seller, $vendor] = sellerAccount();
        $listing = fbnListing($warehouse, 0, $vendor);
        $inbound = app(InboundShipments::class);
        $shipment = $inbound->submit($inbound->draft($vendor, $warehouse->id, [['listing_id' => $listing->id, 'quantity' => 4]], [], $seller->id), $seller->id);
        $staff = adminAccount('uae', 'warehouse');

        $this->actingAs($staff)->get(onPanel('admin', 'inventory'))->assertOk()->assertSee($warehouse->code);
        $this->get(onPanel('admin', 'inventory/inbound'))->assertOk()->assertSee($shipment->number);
        $this->post(onPanel('admin', "inventory/inbound/{$shipment->id}/arrive"))->assertSessionHasNoErrors();
        $this->post(onPanel('admin', "inventory/inbound/{$shipment->id}/receive"))->assertSessionHasNoErrors();
        $this->post(onPanel('admin', 'inventory/inbound/lines/'.$shipment->lines()->value('id').'/receive'), ['good' => 4, 'damaged' => 0, 'bin_code' => 'C-1'])->assertSessionHasNoErrors();
        $this->post(onPanel('admin', "inventory/inbound/{$shipment->id}/finish"))->assertSessionHasNoErrors();
        $this->get(onPanel('admin', "inventory/inbound/{$shipment->id}"))->assertOk()->assertSee('C-1');
        $this->put(onPanel('admin', 'inventory/fees'), [])->assertForbidden();

        expect($shipment->refresh()->status)->toBe(InboundStatus::Received)->and(sellable($listing))->toBe(4);

        $marketing = adminAccount('uae', 'marketing');
        $this->actingAs($marketing)->get(onPanel('admin', 'inventory'))->assertForbidden();
    });
});

describe('transfers and removals', function () {
    it('moves stock between warehouses and counts missing units as lost', function () {
        $from = fbnWarehouse('DXB-1');
        $to = fbnWarehouse('AUH-1');
        $listing = fbnListing($from, 10);
        $transfers = app(StockTransfers::class);

        $transfer = $transfers->request($from->id, $to->id, [['listing_id' => $listing->id, 'quantity' => 6]], null, 1);
        $transfers->dispatch($transfer, 1);
        expect(sellable($listing))->toBe(4)->and(warehouseRow($from, $listing)->on_hand)->toBe(4);

        $transfers->receive($transfer->refresh(), [$transfer->lines()->value('id') => 5], 1);
        expect($transfer->status)->toBe(TransferStatus::Received)->and(warehouseRow($to, $listing)->on_hand)->toBe(5)->and(sellable($listing))->toBe(9);
        expect(fn () => $transfers->request($from->id, $from->id, [['listing_id' => $listing->id, 'quantity' => 1]], null, 1))->toThrow(TransitionRefused::class);
    });

    it('holds removal units off sale, charges the removal fee on the statement when done and releases a cancelled request', function () {
        $warehouse = fbnWarehouse();
        [$seller, $vendor] = sellerAccount();
        $listing = fbnListing($warehouse, 10, $vendor, grams: 2000);
        $this->stock->markDamaged($warehouse, $listing, 2, 'wet', 1);
        $removals = app(RemovalOrders::class);
        $address = ['name' => 'Store', 'phone' => '+971500000000', 'city' => 'Dubai', 'street' => 'Al Quoz 3'];

        $order = $removals->request($vendor, $warehouse->id, RemovalType::ReturnToSeller, [
            ['listing_id' => $listing->id, 'quantity' => 3, 'source' => 'sellable'],
            ['listing_id' => $listing->id, 'quantity' => 2, 'source' => 'damaged'],
        ], $address, 'Season over', $seller->id);

        // 5 units × 0.30 + 10 kg × 1.00 = 11.50 → minimum 15.00 AED.
        expect((string) $order->fee)->toBe('15.0000')->and(sellable($listing))->toBe(5)->and(warehouseRow($warehouse, $listing)->reserved)->toBe(3);

        $removals->process($order, 1);
        $removals->complete($order->refresh(), 1);

        $row = warehouseRow($warehouse, $listing);
        expect($order->status)->toBe(RemovalStatus::Completed)->and($row->on_hand)->toBe(5)->and($row->reserved)->toBe(0)->and($row->damaged)->toBe(0)
            ->and(VendorBalanceItem::query()->where('type', VendorBalanceItem::TYPE_FBN_REMOVAL)->sole()->amount)->toBe('-15.0000');
        assertLedgerBalanced();

        $cancelled = $removals->request($vendor, $warehouse->id, RemovalType::Disposal, [['listing_id' => $listing->id, 'quantity' => 4]], null, null, $seller->id);
        expect(sellable($listing))->toBe(1);
        $removals->cancel($cancelled, ActorType::Vendor, $seller->id);
        expect($cancelled->status)->toBe(RemovalStatus::Cancelled)->and(sellable($listing))->toBe(5);
    });
});

describe('fees', function () {
    it('charges no FBN fee by default (owner decision F11)', function () {
        config(['nawy.fbn.fees' => (require config_path('nawy.php'))['fbn']['fees']]);
        $listing = fbnListing(fbnWarehouse(), 0, grams: 1200);

        expect((string) app(FbnFees::class)->outboundPerUnit($listing, '100.00', 'AED')->getAmount())->toBe('0.00');
    });

    it('prices the outbound fee by weight tier and price band', function () {
        $warehouse = fbnWarehouse();
        $fees = app(FbnFees::class);

        expect((string) $fees->outboundPerUnit(fbnListing($warehouse, 0, grams: 90), '20.00', 'AED')->getAmount())->toBe('5.50')
            ->and((string) $fees->outboundPerUnit(fbnListing($warehouse, 0, grams: 90), '26.00', 'AED')->getAmount())->toBe('7.50')
            ->and((string) $fees->outboundPerUnit(fbnListing($warehouse, 0, grams: 1200), '100.00', 'AED')->getAmount())->toBe('10.50')
            ->and((string) $fees->outboundPerUnit(fbnListing($warehouse, 0, grams: 21500), '100.00', 'AED')->getAmount())->toBe('37.00');
    });

    it('converts the placeholders to 3-decimal currencies and lets admins override the table per country', function () {
        $this->actAsCountry('omn');
        $warehouse = fbnWarehouse('MCT-1');
        $listing = fbnListing($warehouse, 0, price: '10.000', grams: 400);

        expect((string) app(FbnFees::class)->outboundPerUnit($listing, '10.000', 'OMR')->getAmount())->toBe('0.800');

        $admin = adminAccount('omn', 'operations');
        $this->actingAs($admin)->get(onPanel('admin', 'inventory/fees'))->assertOk();
        $this->put(onPanel('admin', 'inventory/fees'), [
            'outbound_price_threshold' => '2.5', 'outbound_extra_per_kg' => '0.1', 'storage_per_cubic_foot' => '0.15', 'long_term_per_cubic_foot' => '2.5',
            'unsellable_per_cubic_foot' => '1.2', 'removal_minimum' => '1.5', 'removal_per_unit' => '0.03', 'removal_per_kg' => '0.1', 'disposal_per_unit' => '0.03',
            'outbound_tiers' => [['max_grams' => 500, 'low' => '0.400', 'high' => '0.650'], ['max_grams' => '', 'low' => '', 'high' => '']],
        ])->assertSessionHasNoErrors();

        expect((string) app(FbnFees::class)->outboundPerUnit($listing, '10.000', 'OMR')->getAmount())->toBe('0.650');
    });

    it('charges the FBN fulfilment fee per unit in the settlement and shows it on the statement', function () {
        $warehouse = fbnWarehouse();
        $listing = fbnListing($warehouse, 5, price: '100.00', grams: 400);
        $subOrder = subOrderFor($listing->vendor, SubOrderStatus::Placed, 2, $listing);
        $fbm = sellableListing('100.00', stock: 3);
        $fbmOrder = subOrderFor($fbm->vendor, SubOrderStatus::Placed, 1, $fbm);

        $result = app(SettlementCalculator::class)->calculate($subOrder);
        expect((string) $result['fbn_fees']->getAmount())->toBe('16.00')
            ->and($result['lines'][0]['fbn_fee'])->toBe('16.00')
            ->and((string) app(SettlementCalculator::class)->calculate($fbmOrder)['fbn_fees']->getAmount())->toBe('0.00');

        moveSubOrderTo($subOrder, SubOrderStatus::Completed);
        expect(SubOrderSettlement::query()->where('sub_order_id', $subOrder->id)->value('fbn_fees'))->toBe('16.0000');
        assertLedgerBalanced();
    });
});

describe('jobs', function () {
    it('snapshots storage daily, flags long-term and unsellable stock and invoices the month once', function () {
        $warehouse = fbnWarehouse();
        $warehouse->forceFill(['capacity_cubic_feet' => '1'])->save();
        [$seller, $vendor] = sellerAccount();
        $listing = fbnListing($warehouse, 10, $vendor);
        $listing->variant->forceFill(['length_cm' => 30, 'width_cm' => 30, 'height_cm' => 31.46])->save(); // ≈ 1 ft³
        $this->stock->markDamaged($warehouse, $listing, 2, 'dented', 1);
        $this->travelTo(CarbonImmutable::parse('2026-10-01 01:00', 'Asia/Muscat'));
        WarehouseStock::query()->update(['first_received_at' => now()->subDays(400), 'damaged_since' => now()->subDays(40)]);
        Artisan::call('nawy:fbn:flag-storage');
        foreach (range(1, 31) as $day) {
            Artisan::call('nawy:fbn:storage-snapshot');
            $this->travel(1)->days();
        }

        $row = warehouseRow($warehouse, $listing);
        expect($row->long_term_flagged_at)->not->toBeNull()->and($row->unsellable_flagged_at)->not->toBeNull()
            ->and(StorageSnapshot::query()->count())->toBe(31)
            ->and($warehouse->refresh()->over_capacity_since)->not->toBeNull();

        Artisan::call('nawy:fbn:invoice-storage', ['--month' => '2026-10']);
        Artisan::call('nawy:fbn:invoice-storage', ['--month' => '2026-10']);

        $invoice = StorageInvoice::query()->sole();
        // Unit volume 30 × 30 × 31.46 cm ÷ 28317 = 0.999894 ft³ (stored dimensions have 2 decimals), every day of the month:
        // 10 units × 1.50 = 15.00, 8 long-term units → 7.9992 ft³ × 25 = 199.98, 2 unsellable → 1.9998 ft³ × 12 = 24.00 (AED).
        expect($invoice->storage_fee)->toBe('15.0000')->and($invoice->long_term_fee)->toBe('199.9800')->and($invoice->unsellable_fee)->toBe('24.0000')
            ->and(VendorBalanceItem::query()->where('type', VendorBalanceItem::TYPE_FBN_STORAGE)->sole()->amount)->toBe('-238.9800');
        assertLedgerBalanced();
    });

    it('reminds sellers of submitted plans not shipped after the reminder days, once a week', function () {
        $warehouse = fbnWarehouse();
        [$seller, $vendor] = sellerAccount();
        $listing = fbnListing($warehouse, 0, $vendor);
        $inbound = app(InboundShipments::class);
        $shipment = $inbound->submit($inbound->draft($vendor, $warehouse->id, [['listing_id' => $listing->id, 'quantity' => 2]], [], $seller->id), $seller->id);

        Artisan::call('nawy:fbn:inbound-reminders');
        expect($shipment->refresh()->reminded_at)->toBeNull();

        $this->travel(4)->days();
        Artisan::call('nawy:fbn:inbound-reminders');
        Artisan::call('nawy:fbn:inbound-reminders');

        expect($shipment->refresh()->reminded_at)->not->toBeNull();
        expect(CustomerNotification::query()->where('user_id', $seller->id)->where('type', 'fbn.inbound_reminder')->count())->toBe(1);
    });
});

describe('vendor app API', function () {
    it('lists FBN stock and creates, submits and ships inbound plans', function () {
        $warehouse = fbnWarehouse();
        [$seller, $vendor] = sellerAccount();
        $listing = fbnListing($warehouse, 8, $vendor);
        $token = $seller->createToken('ops', ['ops:vendor'])->plainTextToken;
        $this->withToken($token);

        $this->getJson('/api/v1/uae-en/ops/vendor/fbn/stock')->assertOk()
            ->assertJsonPath('data.0.sku', $listing->variant->sku)->assertJsonPath('data.0.available', 8)->assertJsonPath('data.0.warehouses.0.code', 'DXB-1');
        $this->getJson('/api/v1/uae-en/ops/vendor/fbn/warehouses')->assertOk()->assertJsonPath('data.0.code', 'DXB-1');

        $created = $this->postJson('/api/v1/uae-en/ops/vendor/fbn/inbound', [
            'warehouse_id' => $warehouse->id, 'lines' => [['listing_id' => $listing->id, 'quantity' => 5, 'cartons' => 1]],
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.can_submit', true);
        $id = $created->json('data.id');

        $this->postJson("/api/v1/uae-en/ops/vendor/fbn/inbound/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.number', sprintf('FBN-IN-AE-%06d', $id));
        $this->postJson("/api/v1/uae-en/ops/vendor/fbn/inbound/{$id}/ship", ['carrier_name' => 'Aramex'])->assertOk()->assertJsonPath('data.status', 'in_transit');
        $this->postJson("/api/v1/uae-en/ops/vendor/fbn/inbound/{$id}/submit")->assertStatus(422)->assertJsonPath('error.code', 'transition_refused');
        $this->getJson('/api/v1/uae-en/ops/vendor/fbn/inbound')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.units', 5);
        $this->postJson("/api/v1/uae-en/ops/vendor/stock/{$listing->id}", ['quantity' => 2, 'reason' => 'restock'])->assertStatus(422)->assertJsonPath('error.code', 'fbn_stock');
    });
});

describe('storefront', function () {
    it('marks FBN offers and promises warehouse handling days', function () {
        $warehouse = fbnWarehouse();
        $listing = fbnListing($warehouse, 3);
        $listing->forceFill(['handling_days' => 4])->save();

        $response = $this->getJson('/api/v1/uae-en/products/'.$listing->variant->product->slug)->assertOk();
        $offer = $response->json('data.buy_box');

        expect($offer['fulfilment'])->toBe('fbn')->and($offer['fulfilled_by_nawy'])->toBeTrue();
        expect(app(ShipmentQuoter::class)->processingDays([['listing' => $listing->load('variant.product'), 'quantity' => 1]]))->toBe(0);

        app(SettingsService::class)->set('fbn.handling_days', 1, $this->country);
        expect(app(ShipmentQuoter::class)->processingDays([['listing' => $listing, 'quantity' => 1]]))->toBe(1);
    });
});

describe('panels', function () {
    it('replaces the Soon entries with the inventory pages', function () {
        [$seller] = sellerAccount();
        $this->actingAs($seller)->get(onPanel('partner', 'inventory'))->assertOk()->assertSee(__('Enrol in FBN'));
        $this->get(onPanel('partner', 'inventory/removals'))->assertOk();
        $this->get(onPanel('partner', 'inventory/fees'))->assertOk()->assertSee(__('Monthly storage invoices'));

        $nav = PanelNavigation::for(Panel::find('partner'), 'partner.inventory');
        $item = collect($nav)->pluck('items')->flatten(1)->firstWhere('label', __('Inventory & warehouses'));
        expect($item['soon'])->toBeFalse()->and($item['active'])->toBeTrue();

        $ops = adminAccount('uae', 'operations');
        foreach (['inventory', 'inventory/warehouses', 'inventory/stock', 'inventory/inbound', 'inventory/pick-pack', 'inventory/transfers', 'inventory/removals', 'inventory/fees'] as $path) {
            $this->actingAs($ops)->get(onPanel('admin', $path))->assertOk();
        }

        $this->post(onPanel('admin', 'inventory/warehouses'), ['code' => 'SHJ-2', 'name_en' => 'Sharjah', 'name_ar' => 'الشارقة', 'city' => 'Sharjah', 'is_active' => '1'])->assertSessionHasNoErrors();
        expect(Warehouse::query()->where('code', 'SHJ-2')->sole()->is_active)->toBeTrue();
    });

    it('lets warehouse staff adjust stock with a reason and run the pick & pack queue', function () {
        $warehouse = fbnWarehouse();
        $listing = fbnListing($warehouse, 4);
        $staff = adminAccount('uae', 'warehouse');

        $this->actingAs($staff)->post(onPanel('admin', 'inventory/stock/adjust'), [
            'warehouse_id' => $warehouse->id, 'sku' => $listing->variant->sku, 'bucket' => 'on_hand', 'quantity' => 2, 'reason' => 'found', 'note' => 'Found on B-2',
        ])->assertSessionHasNoErrors();
        expect(sellable($listing))->toBe(6)
            ->and(WarehouseStockMovement::query()->where('reason', 'adjustment_found')->sole()->actor_user_id)->toBe($staff->id);

        [$customer, $token] = customerWithToken();
        $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1])->assertOk();
        $this->withToken($token)->postJson('/api/v1/uae-en/orders', [
            'payment_method' => 'cod', 'address' => ['name' => 'Sara Ahmed', 'phone' => '+971500000000', 'city' => 'Dubai', 'area' => 'Marina', 'street' => 'Gate 2'],
        ])->assertCreated();
        app('auth')->forgetGuards();
        $subOrderId = FbnReservation::query()->value('sub_order_id');

        $this->actingAs($staff)->get(onPanel('admin', 'inventory/pick-pack'))->assertOk()->assertSee('A-01');
        $this->post(onPanel('admin', "inventory/pick-pack/{$subOrderId}"), ['to' => 'confirmed'])->assertSessionHasNoErrors();
        expect(SubOrder::query()->findOrFail($subOrderId)->status)->toBe(SubOrderStatus::Confirmed);
    });
});

describe('mixed seller groups (owner decision 2026-11-19: split into two parcels)', function () {
    /**
     * One seller, one FBN listing (2 × 100) and one listing the seller ships
     * (1 × 50), bought together by COD.
     *
     * @return array{0: Listing, 1: Listing, 2: SubOrder, 3: SubOrder, 4: string}
     */
    function placeMixedFbnOrder(object $test): array
    {
        $warehouse = fbnWarehouse();
        $fbn = fbnListing($warehouse, 6, price: '100.00');
        $own = sellableListing('50.00', stock: 5, vendor: $fbn->vendor);
        [, $token] = customerWithToken();

        $test->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $fbn->id, 'quantity' => 2])->assertOk();
        $test->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $own->id, 'quantity' => 1])->assertOk();
        $number = $test->withToken($token)->postJson('/api/v1/uae-en/orders', [
            'payment_method' => 'cod',
            'address' => ['name' => 'Sara Ahmed', 'phone' => '+971500000000', 'city' => 'Dubai', 'area' => 'Marina', 'street' => 'Gate 2'],
        ])->assertCreated()->json('data.number');

        $parts = SubOrder::query()->where('vendor_id', $fbn->vendor_id)->orderBy('id')->get();
        expect($parts)->toHaveCount(2);

        return [$fbn, $own, $parts[0], $parts[1], $token];
    }

    it('splits the seller group into a seller parcel and a warehouse parcel at checkout', function () {
        [$fbn, $own, $seller, $warehouse] = placeMixedFbnOrder($this);

        expect($seller->fulfilled_by)->toBe('seller')->and($seller->split_from_sub_order_id)->toBeNull()
            ->and($seller->items->pluck('listing_id')->all())->toBe([$own->id])
            ->and($warehouse->fulfilled_by)->toBe('nawy')->and($warehouse->split_from_sub_order_id)->toBe($seller->id)
            ->and($warehouse->items->pluck('listing_id')->all())->toBe([$fbn->id])
            ->and($warehouse->subtotal)->toBe('200.00')->and($warehouse->shipping_total)->toBe('0.00')->and($warehouse->total)->toBe('200.00')
            ->and($seller->subtotal)->toBe('50.00')
            ->and($seller->number)->not->toBe($warehouse->number);

        // Reserved warehouse units belong to the warehouse parcel only.
        expect(FbnReservation::query()->pluck('sub_order_id')->unique()->all())->toBe([$warehouse->id]);

        // The two parcels add up to what the customer pays.
        $order = $seller->order;
        expect(bcadd((string) $seller->total, (string) $warehouse->total, 2))->toBe(bcsub((string) $order->grand_total, (string) $order->cod_fee, 2));

        $orders = app(FbnOrders::class);
        expect($orders->isWarehouseFulfilled($warehouse))->toBeTrue()
            ->and($orders->isWarehouseFulfilled($seller))->toBeFalse()
            ->and($orders->queue()->pluck('id')->all())->toBe([$warehouse->id])
            ->and(app(SellerFulfilment::class)->nextStepFor($seller))->toBe(SubOrderStatus::Confirmed)
            ->and(app(SellerFulfilment::class)->nextStepFor($warehouse))->toBeNull();
    });

    it('ships the two parcels separately, each with its own shipment, tracking and COD amount', function () {
        [$fbn, , $seller, $warehouse, $token] = placeMixedFbnOrder($this);

        foreach ([SubOrderStatus::Confirmed, SubOrderStatus::Processing, SubOrderStatus::Packed, SubOrderStatus::Shipped] as $step) {
            app(FbnOrders::class)->advance($warehouse->refresh(), $step, 1);
        }

        // The warehouse dispatched its parcel; the seller's parcel is untouched.
        expect($warehouse->refresh()->status)->toBe(SubOrderStatus::Shipped)
            ->and($seller->refresh()->status)->toBe(SubOrderStatus::Placed)
            ->and(FbnReservation::query()->where('sub_order_id', $warehouse->id)->value('status'))->toBe(ReservationStatus::Dispatched)
            ->and(Shipment::query()->where('sub_order_id', $seller->id)->exists())->toBeFalse();

        $sellerFulfilment = app(SellerFulfilment::class);
        while (($next = $sellerFulfilment->nextStepFor($seller->refresh())) !== null && $seller->status !== SubOrderStatus::Packed) {
            $sellerFulfilment->advance($seller, $next, $fbn->vendor->owner_user_id);
        }
        $sellerShipment = app(DeliveryDispatch::class)->shipmentFor($seller->refresh(), ActorType::Vendor, $fbn->vendor->owner_user_id);
        $warehouseShipment = Shipment::query()->where('sub_order_id', $warehouse->id)->sole();

        expect($sellerShipment->number)->not->toBe($warehouseShipment->number)
            ->and(Shipment::query()->count())->toBe(2);

        // COD is collected per parcel; the order's COD fee rides on one of them only.
        $dispatch = app(DeliveryDispatch::class);
        $order = $seller->order;
        expect(bcadd((string) $dispatch->expectedCod($seller), (string) $dispatch->expectedCod($warehouse), 2))->toBe(bcadd((string) $order->grand_total, '0', 2));

        // The customer sees two parcels, the warehouse one marked as shipped by Nawy.
        $shipments = $this->withToken($token)->getJson("/api/v1/uae-en/orders/{$order->number}")->assertOk()->json('data.shipments');
        expect($shipments)->toHaveCount(2)
            ->and(array_column($shipments, 'fulfilled_by'))->toBe(['seller', 'nawy'])
            ->and($shipments[1]['status'])->toBe('shipped');
    });

    it('cancels, settles and charges fees per parcel (fixed fee once, FBN fee 0 by default)', function () {
        config(['nawy.fbn.fees' => null]);
        [$fbn, $own, $seller, $warehouse] = placeMixedFbnOrder($this);
        $calculator = app(SettlementCalculator::class);

        $sellerSettlement = $calculator->calculate($seller);
        $warehouseSettlement = $calculator->calculate($warehouse);
        expect((string) $warehouseSettlement['fbn_fees']->getAmount())->toBe('0.00')
            ->and($warehouseSettlement['fees']->isZero())->toBeTrue()
            ->and((string) $sellerSettlement['fees']->getAmount())->toBe((string) $calculator->calculate(subOrderFor($fbn->vendor, SubOrderStatus::Placed, 1, $own))['fees']->getAmount());

        // Cancelling the seller's parcel leaves the warehouse parcel and its reservation alone.
        app(SellerFulfilment::class)->cancel($seller, 'Out of stock', $fbn->vendor->owner_user_id);
        expect($seller->refresh()->status)->toBe(SubOrderStatus::Cancelled)
            ->and($warehouse->refresh()->status)->toBe(SubOrderStatus::Placed)
            ->and(FbnReservation::query()->where('sub_order_id', $warehouse->id)->value('status'))->toBe(ReservationStatus::Reserved);

        // The warehouse parcel completes and settles on its own.
        foreach ([SubOrderStatus::Confirmed, SubOrderStatus::Processing, SubOrderStatus::Packed, SubOrderStatus::Shipped] as $step) {
            app(FbnOrders::class)->advance($warehouse->refresh(), $step, 1);
        }
        moveSubOrderTo($warehouse->refresh(), SubOrderStatus::Completed);
        expect(SubOrderSettlement::query()->where('sub_order_id', $warehouse->id)->value('fbn_fees'))->toBe('0.0000');
        assertLedgerBalanced();
    });

    it('shares the seller discount between the parcels pro rata to their goods', function () {
        $warehouse = fbnWarehouse();
        $fbn = fbnListing($warehouse, 3, price: '300.00');
        $own = sellableListing('100.00', stock: 3, vendor: $fbn->vendor);
        $money = fn (string $amount) => Money::of($amount, 'AED');
        $line = fn (Listing $listing, string $total) => ['listing_id' => $listing->id, 'line_total' => $money($total), 'warranty_total' => $money('0')];
        $group = [
            'vendor_id' => $fbn->vendor_id, 'lines' => [$line($fbn, '300.00'), $line($own, '100.00')],
            'subtotal' => $money('400.00'), 'discount' => $money('40.00'), 'shipping' => $money('15.00'), 'shipping_cost' => $money('15.00'),
            'shipping_subsidy_vendor' => $money('0'), 'shipping_subsidy_platform' => $money('0'), 'shipping_absorbed' => $money('0'),
            'warranty' => $money('0'), 'total' => $money('375.00'),
        ];

        $parcels = (fn (array $group, $listings) => $this->parcels($group, $listings))
            ->call(app(PlaceOrder::class), $group, Listing::query()->whereIn('id', [$fbn->id, $own->id])->get()->keyBy('id'));

        expect($parcels)->toHaveCount(2)
            ->and($parcels[0]['fulfilled_by'])->toBe('seller')->and((string) $parcels[0]['discount']->getAmount())->toBe('10.00')
            ->and((string) $parcels[0]['total']->getAmount())->toBe('105.00')->and((string) $parcels[0]['shipping']->getAmount())->toBe('15.00')
            ->and($parcels[1]['fulfilled_by'])->toBe('nawy')->and((string) $parcels[1]['discount']->getAmount())->toBe('30.00')
            ->and((string) $parcels[1]['total']->getAmount())->toBe('270.00')->and((string) $parcels[1]['shipping']->getAmount())->toBe('0.00');
    });
});
