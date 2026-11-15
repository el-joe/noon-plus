<?php

use App\Domains\Disputes\Enums\DisputeReason;
use App\Domains\Disputes\Enums\DisputeStatus;
use App\Domains\Disputes\Support\Disputes;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Refunds\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
    Notification::fake();
});

it('QC-001: the seller replies in Seller Center and the admin reviews, escalates, resolves with a refund and closes', function () {
    [$seller, $vendor] = sellerAccount();
    [$order, $customer] = placeOrderVia('card', '100.00', vendor: $vendor);
    captureOrderPayment($order);
    $subOrder = moveSubOrderTo($order->subOrders()->sole(), SubOrderStatus::Delivered);
    $dispute = app(Disputes::class)->open($subOrder, $customer->id, DisputeReason::cases()[0], 'The item is broken.');

    [$otherSeller] = sellerAccount();
    $this->actingAs($otherSeller)->get(onPanel('partner', "disputes/{$dispute->id}"))->assertNotFound();
    $this->actingAs($seller)->get(onPanel('partner', 'disputes'))->assertOk()->assertSee($dispute->number);
    $this->actingAs($seller)->post(onPanel('partner', "disputes/{$dispute->id}/reply"), ['body' => 'We can replace it.'])->assertRedirect();
    expect($dispute->refresh()->status)->toBe(DisputeStatus::SellerResponded);

    $support = adminAccount('uae', 'support');
    $this->actingAs($support)->get(onPanel('admin', "disputes/{$dispute->id}"))->assertOk()->assertSee('We can replace it.');
    $this->actingAs($support)->post(onPanel('admin', "disputes/{$dispute->id}/review"))->assertForbidden();

    $admin = adminAccount();
    $this->actingAs($admin)->post(onPanel('admin', "disputes/{$dispute->id}/review"))->assertRedirect();
    $this->actingAs($admin)->post(onPanel('admin', "disputes/{$dispute->id}/escalate"), ['reason' => 'Needs finance'])->assertRedirect();
    $this->actingAs($admin)->post(onPanel('admin', "disputes/{$dispute->id}/resolve"), ['resolution' => 'split', 'refund_amount' => '40.00'])->assertRedirect()->assertSessionHasNoErrors();

    $dispute->refresh();
    expect($dispute->status)->toBe(DisputeStatus::Resolved)
        ->and((string) $dispute->refund_amount)->toBe('40.0000')
        ->and(Refund::query()->whereKey($dispute->refund_id)->exists())->toBeTrue();

    $this->actingAs($admin)->post(onPanel('admin', "disputes/{$dispute->id}/close"))->assertRedirect();
    expect($dispute->refresh()->status)->toBe(DisputeStatus::Closed);
});
