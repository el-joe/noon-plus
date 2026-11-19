<?php

use App\Domains\Customers\Models\CustomerNotification;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Models\VendorBalanceItem;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Fulfilment\Enums\PackagingOrderStatus;
use App\Domains\Fulfilment\Models\PackagingOrder;
use App\Domains\Fulfilment\Models\PackagingSupply;
use App\Shared\StateMachine\StateTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/*
| QC-016: packaging supplies and orders (SRS §4.1.3, lifecycle catalogue §6.3).
*/

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
    Notification::fake();
});

it('lets admins run the packaging catalogue and delivery fee, and a store order supplies charged to its next payout', function () {
    $admin = adminAccount();
    [$seller, $vendor] = sellerAccount();

    $this->actingAs($admin)->post(onPanel('admin', 'packaging/supplies'), [
        'sku' => 'BOX-M', 'name_en' => 'Medium box', 'name_ar' => 'صندوق متوسط', 'unit_cost' => '2.50', 'min_quantity' => 10, 'max_quantity' => 500, 'is_active' => '1',
    ])->assertSessionHasNoErrors();
    $this->post(onPanel('admin', 'packaging/supplies'), ['sku' => 'TAPE', 'name_en' => 'Tape', 'name_ar' => 'شريط', 'unit_cost' => '4', 'min_quantity' => 1, 'is_active' => '1'])->assertSessionHasNoErrors();
    $this->post(onPanel('admin', 'packaging/delivery-fee'), ['delivery_fee' => '15'])->assertSessionHasNoErrors();
    $this->get(onPanel('admin', 'packaging'))->assertOk()->assertSee('BOX-M');
    [$box, $tape] = PackagingSupply::query()->orderBy('id')->get()->all();

    $address = ['street' => 'Al Quoz 3', 'area' => 'Al Quoz', 'city' => 'Dubai'];
    $this->actingAs($seller)->get(onPanel('partner', 'packaging'))->assertOk()->assertSee('Medium box');
    $this->post(onPanel('partner', 'packaging'), ['quantities' => [$box->id => 5], 'address' => $address])->assertSessionHasErrors('action');
    $this->post(onPanel('partner', 'packaging'), ['quantities' => [$box->id => 0], 'address' => $address])->assertSessionHasErrors('action');
    $this->post(onPanel('partner', 'packaging'), ['quantities' => [$box->id => 20, $tape->id => 2], 'address' => $address])->assertSessionHasNoErrors();

    $order = PackagingOrder::query()->sole();
    expect($order->status)->toBe(PackagingOrderStatus::Pending)
        ->and((string) $order->items_total)->toBe('58.0000')
        ->and((string) $order->delivery_fee)->toBe('15.0000')
        ->and((string) $order->total)->toBe('73.0000')
        ->and($order->items()->where('sku', 'BOX-M')->value('unit_cost'))->toBe('2.5000')
        ->and(ledgerBalance(AccountRef::vendor($vendor->id)))->toBe('-73.00')
        ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::ShippingRevenue)))->toBe('15.00')
        ->and(VendorBalanceItem::query()->where('type', VendorBalanceItem::TYPE_PACKAGING)->sole()->amount)->toBe('-73.0000');

    // A later price change does not touch the order (unit cost snapshotted).
    $this->actingAs($admin)->put(onPanel('admin', "packaging/supplies/{$box->id}"), ['sku' => 'BOX-M', 'name_en' => 'Medium box', 'name_ar' => 'صندوق متوسط', 'unit_cost' => '9', 'min_quantity' => 10, 'is_active' => '1'])->assertSessionHasNoErrors();
    expect((string) $order->refresh()->total)->toBe('73.0000');

    $this->get(onPanel('admin', 'packaging/orders'))->assertOk()->assertSee($order->number);
    $this->post(onPanel('admin', "packaging/orders/{$order->id}/ship"))->assertSessionHasErrors('action');
    $this->post(onPanel('admin', "packaging/orders/{$order->id}/approve"))->assertSessionHasNoErrors();
    $this->post(onPanel('admin', "packaging/orders/{$order->id}/ship"), ['carrier_name' => 'Aramex', 'tracking_number' => 'AX-99'])->assertSessionHasNoErrors();
    $this->post(onPanel('admin', "packaging/orders/{$order->id}/deliver"))->assertSessionHasNoErrors();

    expect($order->refresh()->status)->toBe(PackagingOrderStatus::Delivered)
        ->and($order->tracking_number)->toBe('AX-99')
        ->and(StateTransition::query()->where('subject_type', 'packaging_order')->pluck('to_status')->all())->toBe(['pending', 'approved', 'shipped', 'delivered'])
        ->and(CustomerNotification::query()->where('user_id', $seller->id)->where('type', 'packaging.shipped')->exists())->toBeTrue();

    $this->actingAs($seller)->get(onPanel('partner', "packaging/{$order->id}"))->assertOk()->assertSee('AX-99');
    assertLedgerBalanced();
});

it('gives the whole charge back when an order is rejected, and keeps other stores out', function () {
    [$seller, $vendor] = sellerAccount();
    [$other] = sellerAccount();
    $supply = PackagingSupply::query()->create(['sku' => 'MAILER', 'name_en' => 'Mailer', 'name_ar' => 'ظرف', 'unit_cost' => '1.25', 'min_quantity' => 1, 'is_active' => true]);

    $this->actingAs($seller)->post(onPanel('partner', 'packaging'), ['quantities' => [$supply->id => 8], 'address' => ['street' => 'a', 'area' => 'b', 'city' => 'Dubai']])->assertSessionHasNoErrors();
    $order = PackagingOrder::query()->sole();
    $this->actingAs($other)->get(onPanel('partner', "packaging/{$order->id}"))->assertNotFound();

    $this->actingAs(adminAccount())->post(onPanel('admin', "packaging/orders/{$order->id}/reject"))->assertSessionHasErrors('reason');
    $this->post(onPanel('admin', "packaging/orders/{$order->id}/reject"), ['reason' => 'Out of mailers this month'])->assertSessionHasNoErrors();

    expect($order->refresh()->status)->toBe(PackagingOrderStatus::Rejected)
        ->and(ledgerBalance(AccountRef::vendor($vendor->id)))->toBe('0.00')
        ->and(VendorBalanceItem::query()->where('type', VendorBalanceItem::TYPE_PACKAGING_REFUND)->sole()->amount)->toBe('10.0000');
    assertLedgerBalanced();
});
