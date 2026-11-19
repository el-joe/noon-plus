<?php

use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Campaigns\Enums\ConversionStatus;
use App\Domains\Campaigns\Models\MarketerConversion;
use App\Domains\Campaigns\Support\Conversions;
use App\Domains\Campaigns\Support\Invitations;
use App\Domains\Classifieds\Enums\ReportStatus;
use App\Domains\Classifieds\Models\ClassifiedCategory;
use App\Domains\Classifieds\Support\ClassifiedAds;
use App\Domains\Classifieds\Support\ClassifiedReports;
use App\Domains\Contracts\Enums\ContractKind;
use App\Domains\Contracts\Enums\ContractVersionStatus;
use App\Domains\Contracts\Enums\SignerType;
use App\Domains\Contracts\Models\ContractSignature;
use App\Domains\Contracts\Models\ContractTemplate;
use App\Domains\Contracts\Support\ContractPublisher;
use App\Domains\Contracts\Support\ContractSigner;
use App\Domains\Documents\Enums\DocumentStatus;
use App\Domains\Documents\Models\DocumentType;
use App\Domains\Documents\Support\Documents;
use App\Domains\Identity\Models\RoleMembership;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Listings\Support\ListingStateMachine;
use App\Domains\Marketers\Enums\MarketerStatus;
use App\Domains\Marketers\Support\MarketerProfiles;
use App\Domains\Marketers\Support\Withdrawals;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Exceptions\SubOrderTransitionRefused;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Support\SubOrderStateMachine;
use App\Domains\Payments\Enums\WebhookEventStatus;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Models\PaymentWebhookEvent;
use App\Domains\Payments\Support\Gateways\FakeGateway;
use App\Domains\Settings\SettingsService;
use App\Domains\Travel\Support\TravelPackages;
use App\Domains\Vendors\Models\Vendor;
use App\Domains\Warranty\Enums\WarrantyPurchaseStatus;
use App\Domains\Warranty\Models\WarrantyPlan;
use App\Domains\Warranty\Models\WarrantyPurchase;
use App\Models\User;
use App\Shared\Money\MoneyFactory;
use App\Shared\StateMachine\StateTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/*
| QA wave 5a (fork C): lifecycle and state-machine minors — QC-031..QC-038,
| QC-053 (history rows for bulk moves).
*/

beforeEach(function () {
    $this->setUpCountries();
    $this->country = $this->actAsCountry('uae');
    Cache::flush();
    Storage::fake('local');
    Notification::fake();
});

function wave5StripePayment(): Payment
{
    [, $token] = customerWithToken();
    $listing = sellableListing('100.00');
    test()->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1]);
    test()->withToken($token)->postJson('/api/v1/uae-en/orders', ['payment_method' => 'stripe', 'address' => ['name' => 'Sara', 'city' => 'Dubai', 'area' => 'Marina', 'street' => 'Gate 2']])->assertCreated();
    app('auth')->forgetGuards();

    return Payment::query()->latest('id')->firstOrFail();
}

/**
 * @param  array<string, mixed>  $payload
 */
function wave5Webhook(array $payload): TestResponse
{
    $body = json_encode($payload);

    return test()->call('POST', '/api/v1/uae-en/payments/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_'.strtoupper(str_replace('-', '_', FakeGateway::HEADER)) => FakeGateway::sign($body),
    ], $body);
}

/**
 * A delivered COD sub-order (admin walk through the machine).
 */
function wave5DeliveredOrder(?Vendor $vendor = null, string $market = 'uae-en'): Order
{
    [$order] = placeOrderVia('cod', $market === 'uae-en' ? '100.00' : '10.000', $market, vendor: $vendor);
    moveSubOrderTo($order->subOrders()->first(), SubOrderStatus::Delivered);

    return $order->refresh();
}

it('QC-031: travel-agency moves record the `agency` actor and webhook moves the `gateway` actor', function () {
    $agency = Vendor::factory()->create(['type' => 'travel']);
    $package = app(TravelPackages::class)->create($agency, ['title_en' => 'Baku 4 nights', 'title_ar' => 'باكو', 'destination_city' => 'Baku', 'nights' => 4], 7);

    expect($package->transitions()->first())->actor_type->toBe('agency')->actor_user_id->toBe(7);

    $payment = wave5StripePayment();
    wave5Webhook(['id' => 'evt_actor', 'gateway_reference' => $payment->gateway_reference, 'status' => 'captured'])->assertOk();

    expect($payment->statusHistory()->reorder('id', 'desc')->first()->actor_type)->toBe(ActorType::Gateway);
});

it('QC-032: a webhook event goes received → verified → processed, a repeat outcome is a duplicate and redeliveries are counted', function () {
    $payment = wave5StripePayment();
    $event = ['id' => 'evt_a', 'gateway_reference' => $payment->gateway_reference, 'status' => 'captured'];

    wave5Webhook($event)->assertOk()->assertJsonPath('data.result', 'processed');
    wave5Webhook($event)->assertOk()->assertJsonPath('data.result', 'duplicate');
    wave5Webhook(['id' => 'evt_b', 'gateway_reference' => $payment->gateway_reference, 'status' => 'captured'])->assertOk()->assertJsonPath('data.result', 'duplicate');
    wave5Webhook(['id' => 'evt_c', 'gateway_reference' => 'unknown', 'status' => 'captured'])->assertOk()->assertJsonPath('data.result', 'ignored');

    $events = PaymentWebhookEvent::query()->get()->keyBy('event_id');
    expect($events['evt_a'])->status->toBe(WebhookEventStatus::Processed)->deliveries->toBe(2)->payment_id->toBe($payment->id)
        ->and($events['evt_b'])->status->toBe(WebhookEventStatus::Duplicate)->payment_id->toBe($payment->id)
        ->and($events['evt_c']->status)->toBe(WebhookEventStatus::Ignored)
        ->and(WebhookEventStatus::Verified->value)->toBe('verified');
});

it('QC-033: a listing waiting for review can be archived', function () {
    $listing = sellableListing('50.00');
    $listing->forceFill(['status' => ListingStatus::PendingReview])->save();

    app(ListingStateMachine::class)->transition($listing, ListingStatus::Archived, 1);

    expect($listing->refresh()->status)->toBe(ListingStatus::Archived);
});

describe('QC-034: sub-order catalogue moves', function () {
    it('lets only an admin or the system take shipped → delivered', function () {
        [$order] = placeOrderVia('cod');
        $subOrder = moveSubOrderTo($order->subOrders()->first(), SubOrderStatus::Shipped);

        expect(fn () => app(SubOrderStateMachine::class)->transition($subOrder->refresh(), SubOrderStatus::Delivered, ActorType::Vendor, 1))
            ->toThrow(SubOrderTransitionRefused::class, 'out for delivery');

        app(SubOrderStateMachine::class)->transition($subOrder->refresh(), SubOrderStatus::Delivered, ActorType::Admin, 1, 'Carrier proof of delivery');
        expect($subOrder->refresh()->status)->toBe(SubOrderStatus::Delivered);
    });

    it('lets an admin force-cancel a delivered shipment with a reason, without putting stock back; sellers cannot', function () {
        $vendor = Vendor::factory()->create();
        $order = wave5DeliveredOrder($vendor);
        $subOrder = $order->subOrders()->first();
        $listing = $subOrder->items()->first()->listing;
        $available = app(InventoryLedger::class)->available($listing);
        $machine = app(SubOrderStateMachine::class);

        expect(fn () => $machine->transition($subOrder->refresh(), SubOrderStatus::Cancelled, ActorType::Vendor, 1, 'No'))->toThrow(SubOrderTransitionRefused::class)
            ->and(fn () => $machine->transition($subOrder->refresh(), SubOrderStatus::Cancelled, ActorType::Admin, 1))->toThrow(SubOrderTransitionRefused::class, 'reason');

        $machine->transition($subOrder->refresh(), SubOrderStatus::Cancelled, ActorType::Admin, 1, 'Counterfeit item, refund ordered');

        expect($subOrder->refresh()->status)->toBe(SubOrderStatus::Cancelled)
            ->and(app(InventoryLedger::class)->available($listing->refresh()))->toBe($available)
            ->and($subOrder->statusHistory()->get()->last())->actor_type->toBe(ActorType::Admin)->reason->toBe('Counterfeit item, refund ordered');
    });
});

it('QC-035: a released conversion becomes paid when a withdrawal covers it, and a paid one is clawed back on reversal', function () {
    marketerDefaults(['influencer_fixed' => '200', 'affiliate_percent' => '10', 'broker_fixed' => '2', 'broker_percent' => '5', 'fee_percent' => '10', 'fee_fixed' => '0']);
    $vendor = Vendor::factory()->create();
    $listing = sellableListing('105.00', 50, vendor: $vendor);
    $campaign = liveCampaign($vendor, $listing);
    [, $marketer] = marketerAccount(['affiliate']);
    $invitation = app(Invitations::class)->joinOpen($campaign, $marketer);
    [$order] = placeOrderVia('cod', '105.00', customer: User::factory()->create(['email_verified_at' => now()]), vendor: $vendor);
    $item = $order->subOrders()->first()->items()->first();
    $item->forceFill(['listing_id' => $listing->id])->save();
    $conversions = app(Conversions::class);

    $conversion = $conversions->approve($conversions->record($invitation, $item->load('subOrder'), 'direct'));
    $conversions->release($conversion->refresh());
    $net = bcadd((string) $conversion->refresh()->net, (string) $conversion->bonus, 2);
    $withdrawals = app(Withdrawals::class);
    $withdrawal = $withdrawals->request($marketer, app(MoneyFactory::class)->of($net, 'AED'));
    $withdrawals->approve($withdrawal, 1);

    expect($conversion->refresh()->status)->toBe(ConversionStatus::Released);
    $withdrawals->process($withdrawal->refresh(), 1);
    expect($conversion->refresh())->status->toBe(ConversionStatus::Paid)->paid_at->not->toBeNull();

    $conversions->reverse($conversion->refresh(), '1', 'Refunded');

    expect(MarketerConversion::query()->sole()->status)->toBe(ConversionStatus::ClawbackPending)
        ->and($conversion->transitions()->pluck('to_status')->all())->toContain('paid', 'clawback_pending');
});

it('QC-036: the legacy `completed` campaign state is terminal and never live', function () {
    expect(CampaignStatus::Completed->isTerminal())->toBeTrue()
        ->and(CampaignStatus::Completed->isLive())->toBeFalse()
        ->and(CampaignStatus::Active->allowedNext())->not->toContain(CampaignStatus::Completed);
});

it('QC-037: a rejected or expired document goes back to pending when the owner uploads a new file', function () {
    $type = DocumentType::query()->create(['country_id' => $this->country->id, 'code' => 'trade_licence', 'name_en' => 'Trade licence', 'name_ar' => 'رخصة', 'business_type' => 'vendor', 'requirement' => 'mandatory']);
    $documents = app(Documents::class);
    $document = $documents->upload($type, 'vendor', 5, UploadedFile::fake()->create('licence.pdf', 20, 'application/pdf'), 9);
    $documents->reject($document, 1, 'Blurry scan');

    $again = $documents->upload($type, 'vendor', 5, UploadedFile::fake()->create('licence-2.pdf', 20, 'application/pdf'), 9);

    expect($again->id)->toBe($document->id)
        ->and($again->refresh())->status->toBe(DocumentStatus::Pending)->rejection_reason->toBeNull()->original_name->toBe('licence-2.pdf')
        ->and($again->transitions()->pluck('to_status')->all())->toBe(['pending', 'rejected', 'pending']);

    $documents->approve($again, 1);
    $again->forceFill(['valid_until' => now()->subDay()->toDateString()])->save();
    $documents->expire();
    $documents->upload($type, 'vendor', 5, UploadedFile::fake()->create('licence-3.pdf', 20, 'application/pdf'), 9);

    expect($again->refresh()->status)->toBe(DocumentStatus::Pending)
        ->and(DocumentStatus::Expired->allowedNext())->toBe([DocumentStatus::Pending]);
});

it('QC-038: scheduled auto-complete runs each country once a day at its own order.auto_complete_time', function () {
    $uae = wave5DeliveredOrder()->subOrders()->first();
    $this->actAsCountry('omn');
    $oman = wave5DeliveredOrder(market: 'omn-en')->subOrders()->first();
    app(SettingsService::class)->set('order.auto_complete_time', '05:00', $this->country);

    $this->travelTo(now()->addDays(20)->setTimezone('Asia/Muscat')->setTime(3, 0)->utc());
    $this->artisan('nawy:orders:auto-complete --scheduled')->assertSuccessful();

    expect($oman->refresh()->status)->toBe(SubOrderStatus::Completed);
    $this->actAsCountry('uae');
    expect($uae->refresh()->status)->toBe(SubOrderStatus::Delivered);

    $this->travel(150)->minutes();
    $this->artisan('nawy:orders:auto-complete --scheduled')->assertSuccessful();
    expect($uae->refresh()->status)->toBe(SubOrderStatus::Completed);
});

describe('QC-053: bulk moves leave history with the actor', function () {
    it('expires warranty plans one by one with a system history row', function () {
        [$order] = placeOrderVia('cod');
        $purchase = WarrantyPurchase::query()->create([
            'order_id' => $order->id, 'order_item_id' => $order->subOrders()->first()->items()->first()->id,
            'warranty_plan_id' => WarrantyPlan::factory()->create()->id, 'customer_user_id' => $order->customer_user_id,
            'status' => WarrantyPurchaseStatus::Active, 'quantity' => 1, 'unit_price' => '20', 'total' => '20', 'duration_months' => 1,
            'starts_at' => now(), 'expires_at' => now()->addMonth(),
        ]);

        $this->travel(2)->months();
        $this->artisan('nawy:warranty:expire')->assertSuccessful();

        expect($purchase->refresh()->status)->toBe(WarrantyPurchaseStatus::Expired)
            ->and($purchase->transitions()->sole())->from_status->toBe('active')->to_status->toBe('expired')->actor_type->toBe('system');
    });

    it('closes the other open reports of an actioned ad through the machine', function () {
        $category = ClassifiedCategory::query()->create(['slug' => 'misc', 'name_en' => 'Misc', 'name_ar' => 'متفرقات', 'vertical' => 'general']);
        $ad = app(ClassifiedAds::class)->create(User::factory()->create()->id, ['category_id' => $category->id, 'title' => 'Old bicycle for sale', 'price' => '100', 'city' => 'Dubai']);
        $reports = app(ClassifiedReports::class);
        $first = $reports->report($ad, User::factory()->create()->id, 'fraud', null);
        $second = $reports->report($ad, User::factory()->create()->id, 'fraud', 'Same scam');

        $reports->decide($first, ReportStatus::Actioned, 1, 'Scam');

        expect($second->refresh()->status)->toBe(ReportStatus::Actioned)
            ->and($second->transitions()->pluck('actor_type', 'to_status')->all())->toBe(['open' => 'customer', 'actioned' => 'admin'])
            ->and($first->transitions()->get()->last()->reason)->toBe('Scam');
    });

    it('supersedes signatures and retires versions one by one, with history', function () {
        $template = ContractTemplate::query()->create(['country_id' => $this->country->id, 'kind' => ContractKind::SellerPlatform, 'name' => 'Seller agreement']);
        $publisher = app(ContractPublisher::class);
        $first = $publisher->publish($publisher->draft($template, ['body_ar' => 'اتفاقية', 'body_en' => 'Agreement v1']), 1);
        $signature = app(ContractSigner::class)->sign($first, SignerType::Vendor, 3, 3, 'Noura Saleh');

        $second = $publisher->publish($publisher->draft($template, ['body_ar' => 'اتفاقية ٢', 'body_en' => 'Agreement v2']), 1);

        expect($first->refresh()->status)->toBe(ContractVersionStatus::Retired)
            ->and($second->status)->toBe(ContractVersionStatus::Published)
            ->and(ContractSignature::query()->find($signature->id)->status)->toBe(ContractSignature::STATUS_SUPERSEDED)
            ->and(StateTransition::query()->where('subject_type', 'contract_signature')->where('subject_id', $signature->id)->sole())
            ->from_status->toBe('active')->to_status->toBe('superseded')->actor_type->toBe('admin')
            ->and(Activity::query()->where('subject_id', $first->id)->where('event', 'updated')->exists())->toBeTrue();
    });

    it('saves each marketer membership on its own so the activity log records it', function () {
        [$user, $marketer] = marketerAccount(['affiliate'], 'pending');

        app(MarketerProfiles::class)->transition($marketer, MarketerStatus::Rejected, 1, 'Incomplete profile');

        $membership = RoleMembership::query()->where('user_id', $user->id)->where('country_id', $this->country->id)->sole();
        expect($membership->status)->toBe('revoked')
            ->and(Activity::query()->where('subject_type', $membership->getMorphClass())->where('subject_id', $membership->id)->where('event', 'updated')->exists())->toBeTrue();
    });
});
