<?php

use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Enums\AgentStatus;
use App\Domains\Delivery\Models\Carrier;
use App\Domains\Delivery\Models\DeliveryAgent;
use App\Domains\Disputes\Enums\DisputeReason;
use App\Domains\Disputes\Support\Disputes;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\GiftCards\Enums\GiftCardStatus;
use App\Domains\GiftCards\Enums\VoucherStatus;
use App\Domains\GiftCards\Models\GiftCard;
use App\Domains\GiftCards\Models\Voucher;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Membership\Models\MembershipPlan;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Panels\Notifications\TeamInvitationNotification;
use App\Domains\Panels\Support\Panel;
use App\Domains\Panels\Support\PanelCountries;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Support\Data\GatewayOutcome;
use App\Domains\Payments\Support\PaymentAttempts;
use App\Domains\Refunds\Enums\RefundStatus;
use App\Domains\Refunds\Models\Refund;
use App\Domains\Returns\Models\ReturnRequest;
use App\Domains\Vendors\Models\Vendor;
use App\Domains\Warranty\Enums\WarrantyClaimStatus;
use App\Domains\Warranty\Support\WarrantyClaims;
use App\Models\User;
use Database\Seeders\AdminRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
    Notification::fake();
});

/**
 * The accept link of the last team invitation emailed.
 */
function lastInvitationUrl(): string
{
    $urls = [];
    Notification::assertSentOnDemand(TeamInvitationNotification::class, function (TeamInvitationNotification $notification) use (&$urls): bool {
        $urls[] = $notification->acceptUrl;

        return true;
    });

    return end($urls);
}

it('QC-003: marketing issues a promotional gift card and voids it; the promo value comes back and the history is kept', function () {
    $marketing = adminAccount('uae', 'marketing');

    $this->actingAs($marketing)->post(onPanel('admin', 'gift-cards'), ['amount' => '150.00', 'recipient_email' => 'friend@example.com'])->assertRedirect()->assertSessionHasNoErrors();
    $card = GiftCard::query()->sole();
    expect($card->status)->toBe(GiftCardStatus::Active)
        ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::GiftCardLiability)))->toBe('150.00');

    $this->actingAs($marketing)->get(onPanel('admin', 'gift-cards'))->assertOk()->assertSee($card->number)->assertDontSee($card->code_encrypted);
    $this->actingAs($marketing)->post(onPanel('admin', "gift-cards/{$card->id}/void"), [])->assertSessionHasErrors('reason');
    $this->actingAs($marketing)->post(onPanel('admin', "gift-cards/{$card->id}/void"), ['reason' => 'Sent to the wrong person'])->assertRedirect()->assertSessionHasNoErrors();

    expect($card->refresh()->status)->toBe(GiftCardStatus::Cancelled)
        ->and((string) $card->balance)->toBe('0.0000')
        ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::GiftCardLiability)))->toBe('0.00');
    assertLedgerBalanced();
    $this->actingAs($marketing)->get(onPanel('admin', "gift-cards/{$card->id}"))->assertOk()->assertSee('Sent to the wrong person');

    $support = adminAccount('uae', 'support');
    $this->actingAs($support)->get(onPanel('admin', 'gift-cards'))->assertOk();
    $this->actingAs($support)->post(onPanel('admin', 'gift-cards'), ['amount' => '10', 'recipient_email' => 'x@example.com'])->assertForbidden();
});

it('QC-003: vouchers are created with a unique code, listed with redemptions, and voided', function () {
    $admin = adminAccount();

    $this->actingAs($admin)->post(onPanel('admin', 'gift-cards/vouchers'), [
        'code' => 'RAMADAN-2026', 'label' => 'Ramadan', 'value' => '20', 'max_redemptions' => 100, 'credit_expiry_days' => 30, 'expires_at' => now()->addMonth()->format('Y-m-d\TH:i'),
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(onPanel('admin', 'gift-cards/vouchers'), [
        'code' => 'RAMADAN-2026', 'label' => 'Again', 'value' => '20', 'max_redemptions' => 1, 'credit_expiry_days' => 30, 'expires_at' => now()->addMonth()->format('Y-m-d\TH:i'),
    ])->assertSessionHasErrors('code');

    $voucher = Voucher::query()->sole();
    $this->actingAs($admin)->get(onPanel('admin', 'gift-cards/vouchers'))->assertOk()->assertSee('Ramadan');
    $this->actingAs($admin)->post(onPanel('admin', "gift-cards/vouchers/{$voucher->id}/void"), ['reason' => 'Campaign cancelled'])->assertRedirect();
    expect($voucher->refresh()->status)->toBe(VoucherStatus::Cancelled);
});

it('QA-011: finance creates a Nawy One plan per country, the storefront offers it, and the subscriptions list works', function () {
    $finance = adminAccount('omn', 'finance');

    $this->actingAs($finance)->post(onPanel('admin', 'membership/plans'), [
        'code' => 'one-monthly', 'name_en' => 'Nawy One', 'name_ar' => 'ناوي ون', 'interval' => 'monthly', 'price' => '2.500', 'trial_days' => 7, 'free_shipping' => 1, 'is_active' => 1,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($finance)->post(onPanel('admin', 'membership/plans'), ['code' => 'bad', 'name_en' => 'x', 'name_ar' => 'x', 'interval' => 'weekly', 'price' => '-1', 'trial_days' => 0])
        ->assertSessionHasErrors(['interval', 'price']);

    $this->getJson('/api/v1/omn-en/membership/plans')->assertOk()->assertJsonPath('data.0.price.amount', '2.500');
    $this->getJson('/api/v1/uae-en/membership/plans')->assertOk()->assertJsonCount(0, 'data');

    $oman = Country::query()->where('code', 'omn')->sole();
    $plan = app(CountryContext::class)->runAs($oman, fn () => MembershipPlan::query()->sole());
    $this->actingAs($finance)->put(onPanel('admin', "membership/plans/{$plan->id}"), [
        'code' => 'one-monthly', 'name_en' => 'Nawy One', 'name_ar' => 'ناوي ون', 'interval' => 'monthly', 'price' => '3.000', 'trial_days' => 7, 'free_shipping' => 1, 'is_active' => 0,
    ])->assertRedirect();
    expect(Activity::query()->where('log_name', 'membership_plans')->where('properties->country', 'omn')->count())->toBe(2);

    $this->actingAs($finance)->get(onPanel('admin', 'membership/subscriptions'))->assertOk();
    $this->actingAs(adminAccount('omn', 'support'))->get(onPanel('admin', 'membership'))->assertForbidden();
});

it('QC-029: a manual refund waits in the queue and needs a second admin (maker-checker); approval pays it out', function () {
    [$order] = placeOrderVia('card', '100.00');
    captureOrderPayment($order);
    $maker = adminAccount('uae', 'finance');
    $checker = adminAccount('uae', 'finance');

    $this->actingAs($maker)->post(onPanel('admin', "orders/{$order->id}/refunds"), ['amount' => '500', 'destination' => 'wallet', 'reason' => 'Too much'])->assertSessionHasErrors('action');
    $this->actingAs($maker)->post(onPanel('admin', "orders/{$order->id}/refunds"), ['amount' => '30.00', 'destination' => 'wallet', 'reason' => 'Goodwill'])->assertRedirect();
    $refund = Refund::query()->sole();
    expect($refund->status)->toBe(RefundStatus::Pending);

    $this->actingAs($maker)->get(onPanel('admin', 'refunds'))->assertOk()->assertSee($refund->number);
    $this->actingAs($maker)->post(onPanel('admin', "refunds/{$refund->id}/approve"))->assertSessionHasErrors('action');
    $this->actingAs($checker)->post(onPanel('admin', "refunds/{$refund->id}/approve"))->assertRedirect()->assertSessionHasNoErrors();
    expect($refund->refresh()->status)->toBe(RefundStatus::Completed);
    assertLedgerBalanced();

    $this->actingAs(adminAccount('uae', 'operations'))->get(onPanel('admin', 'refunds'))->assertForbidden();
});

it('QC-029: a pending refund can be rejected with a reason', function () {
    [$order] = placeOrderVia('card', '100.00');
    captureOrderPayment($order);
    $this->actingAs(adminAccount('uae', 'finance'))->post(onPanel('admin', "orders/{$order->id}/refunds"), ['amount' => '10', 'destination' => 'original', 'reason' => 'Ask'])->assertRedirect();
    $refund = Refund::query()->sole();

    $this->actingAs(adminAccount())->post(onPanel('admin', "refunds/{$refund->id}/reject"), ['reason' => 'Not eligible'])->assertRedirect();
    expect($refund->refresh()->status)->toBe(RefundStatus::Rejected);
});

it('QA-009 follow-up: finance resolves payments held under review — accept, mark paid after reconciliation, or refund', function (string $action, PaymentStatus $expected, string $captured) {
    [$order] = placeOrderVia('card', '200.00');
    $payment = $order->payments()->latest('id')->firstOrFail();
    app(PaymentAttempts::class)->applyOutcome($payment, new GatewayOutcome(PaymentStatus::Captured, $payment->gateway_reference, '150.00'), 'webhook');
    expect($payment->refresh()->status)->toBe(PaymentStatus::UnderReview);
    $finance = adminAccount('uae', 'finance');

    $this->actingAs($finance)->get(onPanel('admin', 'finance/payments-review'))->assertOk()->assertSee($payment->reference);
    $this->actingAs($finance)->post(onPanel('admin', "finance/payments-review/{$payment->id}"), ['action' => $action, 'note' => 'Bank statement 14 Nov'])->assertRedirect()->assertSessionHasNoErrors();

    $payment->refresh();
    expect($payment->status)->toBe($expected)
        ->and((string) $payment->captured_amount)->toBe($captured)
        ->and($payment->statusHistory()->reorder('id', 'desc')->first()->actor_user_id)->toBe($finance->id);
    assertLedgerBalanced();

    $this->actingAs($finance)->post(onPanel('admin', "finance/payments-review/{$payment->id}"), ['action' => 'fail', 'note' => 'again'])->assertSessionHasErrors('action');
})->with([
    'accept the partial capture' => ['accept', PaymentStatus::Captured, '150.0000'],
    'mark paid in full' => ['mark_paid', PaymentStatus::Captured, '200.0000'],
    'refund the capture' => ['refund', PaymentStatus::Refunded, '150.0000'],
]);

it('QA-009 follow-up: only finance roles may resolve payments under review', function () {
    $this->actingAs(adminAccount('uae', 'operations'))->get(onPanel('admin', 'finance/payments-review'))->assertForbidden();
});

it('QC-002: an admin handles a warranty claim through review, approval and a refund; the dispute on the same shipment is linked', function () {
    [$order, $customer] = placeOrderVia('card', '100.00');
    captureOrderPayment($order);
    $subOrder = moveSubOrderTo($order->subOrders()->sole(), SubOrderStatus::Delivered);
    $claim = app(WarrantyClaims::class)->submit($subOrder->items()->sole(), $customer->id, 'It stopped working.');
    $dispute = app(Disputes::class)->open($subOrder, $customer->id, DisputeReason::cases()[0], 'Broken.');
    $ops = adminAccount('uae', 'operations');

    $this->actingAs($ops)->get(onPanel('admin', 'warranty-claims'))->assertOk()->assertSee($claim->number);
    $this->actingAs($ops)->get(onPanel('admin', "warranty-claims/{$claim->id}"))->assertOk()->assertSee('It stopped working.')->assertSee($dispute->number);
    $this->actingAs($ops)->get(onPanel('admin', "disputes/{$dispute->id}"))->assertOk()->assertSee($claim->number);

    $this->actingAs($ops)->post(onPanel('admin', "warranty-claims/{$claim->id}/messages"), ['body' => 'Please send a photo.'])->assertRedirect();
    $this->actingAs($ops)->post(onPanel('admin', "warranty-claims/{$claim->id}/review"))->assertRedirect();
    $this->actingAs($ops)->post(onPanel('admin', "warranty-claims/{$claim->id}/approve"))->assertRedirect();
    $this->actingAs($ops)->post(onPanel('admin', "warranty-claims/{$claim->id}/fulfil"), ['outcome' => 'refund'])->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($ops)->post(onPanel('admin', "warranty-claims/{$claim->id}/close"))->assertSessionHasErrors('action');

    $claim->refresh();
    expect($claim->status)->toBe(WarrantyClaimStatus::RefundIssued)
        ->and(Refund::query()->whereKey($claim->refund_id)->exists())->toBeTrue();

    // QC-045 (wave 3): Support approves warranty claims (matrix §3.2 = A); Finance only views.
    $finance = adminAccount('uae', 'finance');
    $this->actingAs($finance)->get(onPanel('admin', "warranty-claims/{$claim->id}"))->assertOk();
    $this->actingAs($finance)->post(onPanel('admin', "warranty-claims/{$claim->id}/resolve"))->assertForbidden();
    $this->actingAs(adminAccount('omn', 'operations'))->get(onPanel('admin', "warranty-claims/{$claim->id}"))->assertNotFound();
});

it('QC-004: an admin invites staff by email with a role in one country; accepting creates the admin membership and role there only', function () {
    $this->seed(AdminRoleSeeder::class);
    $super = adminAccount();
    $this->actingAs($super)->post(onPanel('admin', 'staff/invitations'), ['name' => 'Huda Finance', 'email' => 'huda@example.com', 'role' => 'finance'])->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($super)->post(onPanel('admin', 'staff/invitations'), ['name' => 'X', 'email' => 'x@example.com', 'role' => 'emperor'])->assertSessionHasErrors('role');
    $url = lastInvitationUrl();

    auth()->logout();
    $this->get($url)->assertOk();
    $this->post($url, ['password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1'])->assertRedirect(onPanel('admin', 'login'));

    $huda = User::query()->where('email', 'huda@example.com')->sole();
    expect($huda->hasMembership(MembershipRole::Admin, $this->country))->toBeTrue();
    $this->actingAs($huda)->get(onPanel('admin', 'finance/payouts'))->assertOk();
    $this->actingAs($huda)->get(onPanel('admin', 'staff'))->assertForbidden();
    expect(app(PanelCountries::class)->for($huda, Panel::find('admin'))->pluck('code')->all())->toBe(['uae']);
});

it('QC-004: role changes and suspension apply to the current country, are audited, and only a super admin manages staff by default', function () {
    $super = adminAccount();
    $staff = adminAccount('uae', 'support');

    $this->actingAs($super)->get(onPanel('admin', 'staff?q='.urlencode($staff->email)))->assertOk()->assertSee($staff->email);
    $this->actingAs($super)->put(onPanel('admin', "staff/{$staff->id}/roles"), ['roles' => ['support', 'content']])->assertRedirect()->assertSessionHasNoErrors();
    app('auth')->forgetGuards();
    $this->actingAs($staff->fresh())->get(onPanel('admin', 'pages'))->assertOk();
    expect(Activity::query()->where('log_name', 'staff')->where('event', 'roles_changed')->sole()->properties['attributes'])->toBe(['content', 'support']);

    $this->actingAs($super)->post(onPanel('admin', "staff/{$staff->id}/status"), ['status' => 'suspended'])->assertRedirect();
    expect($staff->fresh()->hasMembership(MembershipRole::Admin, $this->country))->toBeFalse();
    $this->actingAs($super)->post(onPanel('admin', "staff/{$super->id}/status"), ['status' => 'suspended'])->assertForbidden();

    $ops = adminAccount('uae', 'operations');
    $this->actingAs($ops)->get(onPanel('admin', 'staff'))->assertForbidden();
    $this->actingAs($ops)->get(onPanel('admin', 'roles'))->assertForbidden();
    $this->actingAs(adminAccount('omn', 'operations'))->get(onPanel('admin', "staff/{$staff->id}?country=omn"))->assertForbidden();
});

it('QC-004: the roles screen shows the matrix; custom roles are created and edited, default roles are read-only', function () {
    $super = adminAccount();
    $this->seed(AdminRoleSeeder::class);

    $this->actingAs($super)->get(onPanel('admin', 'roles'))->assertOk()->assertSee('coupons.manage')->assertSee('Account manager');
    $this->actingAs($super)->post(onPanel('admin', 'roles'), ['name' => 'Catalog Editor', 'permissions' => ['categories.view', 'categories.manage']])->assertRedirect()->assertSessionHasNoErrors();
    $role = Role::query()->where('name', 'catalog_editor')->sole();
    expect($role->permissions->pluck('name')->sort()->values()->all())->toBe(['categories.manage', 'categories.view']);

    $this->actingAs($super)->put(onPanel('admin', "roles/{$role->id}"), ['permissions' => ['bogus.permission']])->assertSessionHasErrors('permissions.0');
    $operations = Role::query()->where('name', 'operations')->sole();
    $this->actingAs($super)->put(onPanel('admin', "roles/{$operations->id}"), ['permissions' => []])->assertForbidden();
    expect(Activity::query()->where('log_name', 'roles')->count())->toBe(1);
});

it('QC-019: an account manager sees only assigned vendors and their orders, returns and disputes; finance is hidden and money masked', function () {
    $manager = adminAccount('uae', 'account_manager');
    [$sellerUser, $mine] = sellerAccount();
    $mine->forceFill(['account_manager_id' => $manager->id])->save();
    $theirs = Vendor::factory()->create();
    [$myOrder, $customer] = placeOrderVia('cod', vendor: $mine);
    [$theirOrder] = placeOrderVia('cod', vendor: $theirs);
    $mySub = moveSubOrderTo($myOrder->subOrders()->sole(), SubOrderStatus::Delivered);
    $theirSub = moveSubOrderTo($theirOrder->subOrders()->sole(), SubOrderStatus::Delivered);
    $theirDispute = app(Disputes::class)->open($theirSub, $theirOrder->customer_user_id, DisputeReason::cases()[0], 'Broken.');
    $myDispute = app(Disputes::class)->open($mySub, $customer->id, DisputeReason::cases()[0], 'Late.');

    $this->actingAs($manager)->get(onPanel('admin', 'vendors'))->assertOk()->assertSee($mine->store_name)->assertDontSee($theirs->store_name);
    $this->actingAs($manager)->get(onPanel('admin', "vendors/{$theirs->id}"))->assertNotFound();
    $this->actingAs($manager)->get(onPanel('admin', "vendors/{$mine->id}"))->assertOk()->assertSee('data-masked="true"', false);
    $this->actingAs($manager)->get(onPanel('admin', 'orders'))->assertOk()->assertSee($myOrder->number)->assertDontSee($theirOrder->number);
    $this->actingAs($manager)->get(onPanel('admin', "orders/{$theirOrder->id}"))->assertNotFound();
    $this->actingAs($manager)->get(onPanel('admin', 'disputes'))->assertOk()->assertSee($myDispute->number)->assertDontSee($theirDispute->number);
    $this->actingAs($manager)->get(onPanel('admin', "disputes/{$theirDispute->id}"))->assertNotFound();
    $this->actingAs($manager)->post(onPanel('admin', "sub-orders/{$theirSub->id}/status"), ['to' => 'completed', 'reason' => 'Trying it'])->assertNotFound();

    $this->actingAs($manager)->get(onPanel('admin', 'finance/payouts'))->assertForbidden();
    $this->actingAs($manager)->get(onPanel('admin', 'refunds'))->assertForbidden();
    $this->actingAs($manager)->post(onPanel('admin', "vendors/{$mine->id}/account-manager"), ['account_manager_id' => null])->assertForbidden();
    $this->actingAs($manager)->get(onPanel('admin', '/'))->assertOk()->assertDontSee('>Finance<', false)->assertSee('Users &amp; vendors', false);

    $super = adminAccount();
    $this->actingAs($super)->get(onPanel('admin', "vendors/{$mine->id}"))->assertOk()->assertSee('data-masked="false"', false);
    $this->actingAs($super)->get(onPanel('admin', 'returns'))->assertOk();
    expect(ReturnRequest::query()->count())->toBe(0);
});

it('QC-019: vendor relations assigns an account manager (audited); only account managers of the country can be chosen', function () {
    $relations = adminAccount('uae', 'vendor_relations');
    $manager = adminAccount('uae', 'account_manager');
    $notManager = adminAccount('uae', 'support');
    $vendor = Vendor::factory()->create();

    $this->actingAs($relations)->post(onPanel('admin', "vendors/{$vendor->id}/account-manager"), ['account_manager_id' => $notManager->id])->assertSessionHasErrors('account_manager_id');
    $this->actingAs($relations)->post(onPanel('admin', "vendors/{$vendor->id}/account-manager"), ['account_manager_id' => $manager->id])->assertRedirect()->assertSessionHasNoErrors();

    expect($vendor->refresh()->account_manager_id)->toBe($manager->id)
        ->and(Activity::query()->where('log_name', 'vendors')->where('event', 'account_manager_assigned')->sole()->properties['country'])->toBe('uae');
});

it('QC-044: the activity log filters by user, subject, scope and Oman dates and never shows another country', function () {
    $admin = adminAccount();
    $marketing = adminAccount('uae', 'marketing');
    $this->actingAs($marketing)->post(onPanel('admin', 'coupons'), [
        'code' => 'LOGME', 'scope' => 'platform', 'funded_by' => 'platform', 'type' => 'percentage', 'value' => '5', 'target_type' => 'all', 'customer_segment' => 'everyone',
    ])->assertRedirect();
    $this->actingAs($admin)->post(onPanel('admin', 'membership/plans?country=omn'), [
        'code' => 'omn-plan', 'name_en' => 'Oman plan', 'name_ar' => 'خطة', 'interval' => 'monthly', 'price' => '1.000', 'trial_days' => 0,
    ])->assertRedirect();

    $this->actingAs($admin)->get(onPanel('admin', 'activity?country=uae&user='.urlencode($marketing->email)))->assertOk()->assertSee('LOGME')->assertDontSee('omn-plan');
    $this->actingAs($admin)->get(onPanel('admin', 'activity?country=uae&subject=Coupon&scope=country'))->assertOk()->assertSee('LOGME');
    $this->actingAs($admin)->get(onPanel('admin', 'activity?country=uae&from=2020-01-01&to=2020-01-02'))->assertOk()->assertDontSee('LOGME');
    $this->actingAs($admin)->get(onPanel('admin', 'activity?country=omn'))->assertOk()->assertSee('omn-plan')->assertDontSee('LOGME');
    $this->actingAs($admin)->get(onPanel('admin', 'activity?from=2026-02-31'))->assertSessionHasErrors('from');

    $this->actingAs(adminAccount('uae', 'marketing'))->get(onPanel('admin', 'activity'))->assertForbidden();
    $this->actingAs(adminAccount('uae', 'finance'))->get(onPanel('admin', 'activity'))->assertOk();
});

it('QC-044: system health shows queues, failed jobs, country databases, mail and storage; failed jobs can be deleted', function () {
    $uuid = (string) Str::uuid();
    DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'redis', 'queue' => 'payments', 'payload' => json_encode(['displayName' => 'App\\Jobs\\CapturePayment']), 'exception' => "RuntimeException: acquirer 503\n#0 trace", 'failed_at' => now()]);
    $admin = adminAccount();

    $this->actingAs($admin)->get(onPanel('admin', 'health'))->assertOk()
        ->assertSee('CapturePayment')->assertSee('acquirer 503')
        ->assertSee('data-database="omn"', false)->assertSee('data-disk="public"', false);
    $this->actingAs($admin)->delete(onPanel('admin', "health/failed-jobs/{$uuid}"))->assertRedirect();
    expect(DB::table('failed_jobs')->count())->toBe(0);

    $ops = adminAccount('uae', 'operations');
    $this->actingAs($ops)->get(onPanel('admin', 'health'))->assertOk();
    $this->actingAs($ops)->delete(onPanel('admin', "health/failed-jobs/{$uuid}"))->assertForbidden();
    $this->actingAs(adminAccount('uae', 'marketing'))->get(onPanel('admin', 'health'))->assertForbidden();
});

it('wave 2: an admin-created driver goes through the team invitation and becomes an agent only on acceptance', function () {
    $ops = adminAccount('uae', 'operations');
    $owner = User::factory()->create();
    $carrier = Carrier::query()->create(['code' => 'swift', 'name' => 'Swift', 'status' => 'active', 'owner_user_id' => $owner->id]);

    $this->actingAs($ops)->post(onPanel('admin', 'delivery/agents'), ['email' => 'Rider@Example.com', 'name' => 'Omar Rider', 'vehicle_type' => 'bike'])->assertRedirect()->assertSessionHasNoErrors();
    expect(DeliveryAgent::query()->count())->toBe(0)
        ->and(User::query()->where('email', 'rider@example.com')->exists())->toBeFalse();
    $this->actingAs($ops)->get(onPanel('admin', 'delivery/agents'))->assertOk()->assertSee('rider@example.com');

    $url = lastInvitationUrl();
    auth()->logout();
    $this->get($url)->assertOk()->assertSee('Nawy');
    $this->post($url, ['password' => 'rider-pass-1', 'password_confirmation' => 'rider-pass-1'])->assertRedirect(onPanel('delivery', 'login'));

    $agent = DeliveryAgent::query()->sole();
    expect($agent->status)->toBe(AgentStatus::PendingDocuments)
        ->and($agent->carrier_id)->toBeNull()
        ->and($agent->vehicle_type)->toBe('bike')
        ->and(User::query()->where('email', 'rider@example.com')->sole()->hasMembership(MembershipRole::DeliveryAgent, $this->country))->toBeTrue();

    $this->actingAs($ops)->post(onPanel('admin', 'delivery/agents'), ['email' => 'rider@example.com', 'name' => 'Again'])->assertSessionHasErrors('action');
    $this->actingAs($ops)->post(onPanel('admin', 'delivery/agents'), ['email' => 'second@example.com', 'name' => 'Second', 'carrier_id' => $carrier->id])->assertRedirect();
    $this->get(lastInvitationUrl())->assertOk()->assertSee('Swift');
});

it('QC-030 / QA-030: the admin sidebar has no # links, no "Soon" entries left (FBN built) and hides pages the role cannot open', function () {
    $super = adminAccount();
    $page = $this->actingAs($super)->get(onPanel('admin', '/'))->assertOk();
    $page->assertDontSee('href="#"', false)->assertDontSee('data-nav-soon', false)->assertSee(onPanel('admin', 'inventory'), false)->assertSee(onPanel('admin', 'catalog'), false)->assertSee(onPanel('admin', 'health'), false);

    $support = adminAccount('uae', 'support');
    $this->actingAs($support)->get(onPanel('admin', '/'))->assertOk()
        ->assertSee(onPanel('admin', 'disputes'), false)
        ->assertDontSee(onPanel('admin', 'finance'), false)
        ->assertDontSee(onPanel('admin', 'staff'), false)
        ->assertDontSee(onPanel('admin', 'coupons'), false);

    [$seller] = sellerAccount();
    $this->actingAs($seller)->get(onPanel('partner', '/'))->assertOk()->assertDontSee('href="#"', false);
});
