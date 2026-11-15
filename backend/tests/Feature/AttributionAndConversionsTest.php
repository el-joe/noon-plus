<?php

use App\Domains\Campaigns\Enums\ConversionStatus;
use App\Domains\Campaigns\Enums\InvitationSource;
use App\Domains\Campaigns\Models\CampaignFeeRule;
use App\Domains\Campaigns\Models\CampaignInvitation;
use App\Domains\Campaigns\Models\MarketerCampaign;
use App\Domains\Campaigns\Models\MarketerConversion;
use App\Domains\Campaigns\Models\ReferralClick;
use App\Domains\Campaigns\Support\Attribution;
use App\Domains\Campaigns\Support\CampaignStateMachine;
use App\Domains\Campaigns\Support\Conversions;
use App\Domains\Campaigns\Support\Invitations;
use App\Domains\Campaigns\Support\Samples;
use App\Domains\Contracts\Enums\ContractKind;
use App\Domains\Contracts\Enums\SignerType;
use App\Domains\Contracts\Models\ContractAssignment;
use App\Domains\Contracts\Models\ContractTemplate;
use App\Domains\Contracts\Support\ContractPublisher;
use App\Domains\Contracts\Support\ContractSigner;
use App\Domains\Countries\Models\Country;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Enums\PayoutSchedule;
use App\Domains\Finance\Enums\WalletBucket;
use App\Domains\Finance\Models\SubOrderSettlement;
use App\Domains\Finance\Models\VendorBalanceItem;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Finance\Support\Payouts;
use App\Domains\Finance\Support\PayoutStatement;
use App\Domains\Listings\Models\Listing;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Marketers\Support\MarketerProfiles;
use App\Domains\Marketers\Support\MarketingSettings;
use App\Domains\Marketers\Support\WhatsAppMessenger;
use App\Domains\Marketers\Support\Withdrawals;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Support\SubOrderStateMachine;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use App\Shared\Money\MoneyFactory;
use App\Shared\StateMachine\TransitionRefused;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
    Cache::flush();
    Storage::fake('local');
    WhatsAppMessenger::fake();
    marketerDefaults(['influencer_fixed' => '200', 'affiliate_percent' => '10', 'broker_fixed' => '2', 'broker_percent' => '5', 'fee_percent' => '10', 'fee_fixed' => '0']);
    $this->vendor = Vendor::factory()->create();
    $this->listing = sellableListing('105.00', 50, vendor: $this->vendor); // basis 100.00 excl. 5% VAT
    $this->campaign = liveCampaign($this->vendor, $this->listing);
});

/**
 * An affiliate who joined the open campaign (accepted invitation).
 *
 * @return array{0: MarketerProfile, 1: CampaignInvitation}
 */
function joinedAffiliate(MarketerCampaign $campaign, array $types = ['affiliate']): array
{
    [, $marketer] = marketerAccount($types);

    return [$marketer, app(Invitations::class)->joinOpen($campaign, $marketer)];
}

function click(string $code, string $visitor = 'visitor-1'): TestResponse
{
    return test()->withHeader('X-Visitor-Token', $visitor)->getJson("/api/v1/uae-en/referrals/{$code}");
}

/**
 * Cash-on-delivery order of the listing through the API.
 *
 * @param  array<string, mixed>  $extra
 */
function orderOf(Listing $listing, ?User $customer = null, array $extra = [], int $quantity = 1): Order
{
    app('auth')->forgetGuards();
    $customer ??= User::factory()->create(['email_verified_at' => now()]);
    $token = $customer->createToken('t')->plainTextToken;
    test()->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => $quantity])->assertOk();
    test()->withToken($token)->postJson('/api/v1/uae-en/orders', [
        'payment_method' => 'cod',
        'address' => ['name' => 'Sara', 'phone' => '+971500000000', 'city' => 'Dubai', 'area' => 'Marina', 'street' => 'Gate 2'],
    ] + $extra)->assertCreated();
    app('auth')->forgetGuards();

    return Order::query()->where('customer_user_id', $customer->id)->latest('id')->firstOrFail();
}

describe('referral validity matrix', function () {
    it('records active and pending clicks and reports the other statuses', function () {
        [$active, $activeInvitation] = joinedAffiliate($this->campaign);
        [, $pendingMarketer] = marketerAccount(['affiliate']);
        $pending = app(Invitations::class)->dispatch($this->campaign, $pendingMarketer, InvitationSource::InviteMore);

        click($activeInvitation->referral_code)->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.tracked', true)
            ->assertJsonPath('data.destination.listing_id', $this->listing->id)->assertJsonPath('data.share.code', $activeInvitation->referral_code);
        click($pending->referral_code)->assertJsonPath('data.status', 'pending')->assertJsonPath('data.tracked', true);
        click('ZZZZZZZZZZ')->assertNotFound()->assertJsonPath('error.code', 'referral_unknown');

        app(Invitations::class)->reject($pending->refresh());
        click($pending->referral_code)->assertJsonPath('data.status', 'grace')->assertJsonPath('data.tracked', false);
        $this->travel(4)->days();
        click($pending->referral_code)->assertJsonPath('data.status', 'expired');

        app(CampaignStateMachine::class)->pause($this->campaign, ActorType::Vendor, 1);
        click($activeInvitation->referral_code)->assertJsonPath('data.status', 'campaign_closed')->assertJsonPath('data.tracked', false);

        expect(ReferralClick::query()->count())->toBe(2)->and($active->exists)->toBeTrue();
    });
});

describe('attribution models', function () {
    it('credits the last click by default with gross = 10% of the line excl. VAT', function () {
        [, $first] = joinedAffiliate($this->campaign);
        [$lastMarketer, $last] = joinedAffiliate($this->campaign);
        click($first->referral_code);
        $this->travel(1)->hours();
        click($last->referral_code);

        orderOf($this->listing, extra: ['visitor_token' => 'visitor-1']);

        expect(MarketerConversion::query()->sole())
            ->invitation_id->toBe($last->id)->marketer_profile_id->toBe($lastMarketer->id)->attribution->toBe('last_click')
            ->basis->toBe('100.0000')->gross->toBe('10.0000')->fee->toBe('1.0000')->net->toBe('9.0000')->status->toBe(ConversionStatus::Pending)
            ->and($this->campaign->refresh()->budget_spent)->toBe('10.0000');
    });

    it('credits the first click or splits linearly when configured, and honours a campaign override', function (string $model, array $expected) {
        app(MarketingSettings::class)->set('attribution_model', $model);
        [, $first] = joinedAffiliate($this->campaign);
        [, $second] = joinedAffiliate($this->campaign);
        click($first->referral_code);
        $this->travel(1)->hours();
        click($second->referral_code);

        orderOf($this->listing, extra: ['visitor_token' => 'visitor-1']);

        $byInvitation = MarketerConversion::query()->get()->mapWithKeys(fn ($conversion) => [$conversion->invitation_id === $first->id ? 'first' : 'second' => [$conversion->weight, $conversion->gross]])->all();
        expect($byInvitation)->toBe($expected);
    })->with([
        'first click' => ['first_click', ['first' => ['1.0000', '10.0000']]],
        'linear' => ['linear', ['first' => ['0.5000', '5.0000'], 'second' => ['0.5000', '5.0000']]],
    ]);

    it('lets a campaign attribution model override the country setting', function () {
        $this->campaign->forceFill(['attribution_model' => 'first_click'])->save();
        [, $first] = joinedAffiliate($this->campaign);
        [, $second] = joinedAffiliate($this->campaign);
        click($first->referral_code);
        $this->travel(1)->minutes();
        click($second->referral_code);

        orderOf($this->listing, extra: ['visitor_token' => 'visitor-1']);

        expect(MarketerConversion::query()->sole()->invitation_id)->toBe($first->id);
    });

    it('gives a direct referral code precedence over clicks', function () {
        [, $clicked] = joinedAffiliate($this->campaign);
        [, $direct] = joinedAffiliate($this->campaign);
        click($clicked->referral_code);

        orderOf($this->listing, extra: ['visitor_token' => 'visitor-1', 'referral_code' => $direct->referral_code]);

        expect(MarketerConversion::query()->sole())->invitation_id->toBe($direct->id)->attribution->toBe('direct');
    });

    it('ignores clicks older than the window, on another listing, and the marketer\'s own purchases', function () {
        [$selfUser] = [User::factory()->create(['email_verified_at' => now()])];
        $self = app(MarketerProfiles::class)->register($selfUser, ['affiliate']);
        $self->forceFill(['global_status' => 'active'])->save();
        $selfInvitation = app(Invitations::class)->joinOpen($this->campaign, $self);
        [, $old] = joinedAffiliate($this->campaign);

        click($old->referral_code, 'old-visitor');
        $this->travel(8)->days();
        orderOf($this->listing, extra: ['visitor_token' => 'old-visitor']);

        $this->withToken($selfUser->createToken('s')->plainTextToken)->getJson("/api/v1/uae-en/referrals/{$selfInvitation->referral_code}")->assertOk();
        app('auth')->forgetGuards();
        orderOf($this->listing, $selfUser);

        $other = sellableListing('105.00', 20, vendor: $this->vendor);
        click($old->referral_code, 'another');
        orderOf($other, extra: ['visitor_token' => 'another']);

        expect(MarketerConversion::query()->count())->toBe(0);
    });

    it('credits a click made while the invitation was pending once it is accepted, and grace clicks after a decline', function () {
        [, $marketer] = marketerAccount(['affiliate']);
        $pending = app(Invitations::class)->dispatch($this->campaign, $marketer, InvitationSource::InviteMore);
        [, $decliner] = marketerAccount(['affiliate']);
        $graceInvitation = app(Invitations::class)->dispatch($this->campaign, $decliner, InvitationSource::InviteMore);
        click($pending->referral_code, 'p');
        click($graceInvitation->referral_code, 'g');
        $this->travel(1)->minutes();

        orderOf($this->listing, extra: ['visitor_token' => 'p']);
        expect(MarketerConversion::query()->count())->toBe(0);

        app(Invitations::class)->accept($pending->refresh());
        app(Invitations::class)->reject($graceInvitation->refresh());
        orderOf($this->listing, extra: ['visitor_token' => 'p']);
        orderOf($this->listing, extra: ['visitor_token' => 'g']);

        expect(MarketerConversion::query()->pluck('invitation_id')->sort()->values()->all())->toBe(collect([$pending->id, $graceInvitation->id])->sort()->values()->all());
    });
});

describe('conversion amounts', function () {
    it('is idempotent per order item and invitation', function () {
        [, $invitation] = joinedAffiliate($this->campaign);
        click($invitation->referral_code);
        $order = orderOf($this->listing, extra: ['visitor_token' => 'visitor-1']);

        app(Attribution::class)->attributeOrder($order, 'visitor-1');

        expect(MarketerConversion::query()->count())->toBe(1)->and($this->campaign->refresh()->budget_spent)->toBe('10.0000');
    });

    it('pays a broker fixed per item plus a percentage', function () {
        [, $invitation] = joinedAffiliate($this->campaign, ['broker']);
        click($invitation->referral_code);

        orderOf($this->listing, extra: ['visitor_token' => 'visitor-1'], quantity: 2);

        expect(MarketerConversion::query()->sole())->basis->toBe('200.0000')->gross->toBe('14.0000')->fee->toBe('1.4000')->net->toBe('12.6000');
    });

    it('shortens the last commission to the budget left and completes the campaign', function () {
        $campaign = liveCampaign($this->vendor, sellableListing('105.00', 50, vendor: $this->vendor), ['budget' => '15']);
        [, $invitation] = joinedAffiliate($campaign);
        click($invitation->referral_code);

        orderOf($campaign->listing, extra: ['visitor_token' => 'visitor-1'], quantity: 2);

        expect(MarketerConversion::query()->sole()->gross)->toBe('15.0000')
            ->and($campaign->refresh()->status->value)->toBe('done');
    });

    it('adds the flash-sale bonus percentage when the item was sold at a flash price', function () {
        app(MarketingSettings::class)->set('flash_sale_bonus_percent', '2');
        [, $invitation] = joinedAffiliate($this->campaign);
        $order = orderOf($this->listing);
        $item = $order->subOrders()->first()->items()->first();
        $item->flash_sale_submission_id = 1; // sold at a flash price (in memory only)

        $conversion = app(Conversions::class)->record($invitation, $item->load('subOrder'), 'direct');

        expect($conversion->refresh()->bonus)->toBe('2.0000');
    });
});

describe('lifecycle, ledger and payouts', function () {
    it('approves after the return window, releases after the clearing days and charges the store', function () {
        [$marketer, $invitation] = joinedAffiliate($this->campaign);
        click($invitation->referral_code);
        $subOrder = moveSubOrderTo(orderOf($this->listing, extra: ['visitor_token' => 'visitor-1'])->subOrders()->first(), SubOrderStatus::Delivered);
        $conversion = MarketerConversion::query()->sole();

        $this->artisan('nawy:conversions:approve')->assertSuccessful();
        expect($conversion->refresh()->status)->toBe(ConversionStatus::Pending);

        $this->travel(15)->days();
        $this->artisan('nawy:conversions:approve');
        expect($conversion->refresh()->status)->toBe(ConversionStatus::Approved)
            ->and(ledgerBalance(AccountRef::marketer($marketer->id, WalletBucket::Pending)))->toBe('9.00')
            ->and(VendorBalanceItem::query()->where('type', 'marketer_commission')->sole()->amount)->toBe('-10.0000');

        $this->travel(13)->days();
        $this->artisan('nawy:conversions:release');
        expect($conversion->refresh()->status)->toBe(ConversionStatus::Approved);

        $this->travel(2)->days();
        $this->artisan('nawy:conversions:release')->assertSuccessful();
        expect($conversion->refresh()->status)->toBe(ConversionStatus::Released)
            ->and(ledgerBalance(AccountRef::marketer($marketer->id, WalletBucket::Pending)))->toBe('0.00')
            ->and(ledgerBalance(AccountRef::marketer($marketer->id, WalletBucket::Spendable)))->toBe('9.00')
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::FeeRevenue)))->toBe('1.00')
            ->and($subOrder->exists)->toBeTrue();
        assertLedgerBalanced();
    });

    it('reverses a pending conversion when the shipment is cancelled and frees the budget', function () {
        [, $invitation] = joinedAffiliate($this->campaign);
        click($invitation->referral_code);
        $order = orderOf($this->listing, extra: ['visitor_token' => 'visitor-1']);

        app(SubOrderStateMachine::class)->transition($order->subOrders()->first(), SubOrderStatus::Cancelled, ActorType::Customer, $order->customer_user_id);

        expect(MarketerConversion::query()->sole()->status)->toBe(ConversionStatus::Reversed)
            ->and($this->campaign->refresh()->budget_spent)->toBe('0.0000');
    });

    it('reduces a pending conversion for a partial return', function () {
        [, $invitation] = joinedAffiliate($this->campaign);
        click($invitation->referral_code);
        $order = orderOf($this->listing, extra: ['visitor_token' => 'visitor-1'], quantity: 2);
        $item = $order->subOrders()->first()->items()->first();

        app(Conversions::class)->reverseForOrderItem($item->id, '0.5', 'Returned');

        expect(MarketerConversion::query()->sole())->status->toBe(ConversionStatus::Pending)->gross->toBe('10.0000')->net->toBe('9.0000')->reversed_gross->toBe('10.0000')
            ->and($this->campaign->refresh()->budget_spent)->toBe('10.0000');
    });

    it('claws back a reversed commission that was already withdrawn and recovers it from the next earnings', function () {
        [$marketer, $invitation] = joinedAffiliate($this->campaign);
        $currency = $this->country->currency;
        $money = app(MoneyFactory::class);
        $conversions = app(Conversions::class);

        $first = $conversions->record($invitation, orderOf($this->listing)->subOrders()->first()->items()->first()->load('subOrder'), 'direct');
        $conversions->approve($first);
        $conversions->release($first->refresh());
        $withdrawal = app(Withdrawals::class)->request($marketer, $money->of('9.00', $currency));
        app(Withdrawals::class)->approve($withdrawal, 1);
        app(Withdrawals::class)->process($withdrawal->refresh(), 1);

        $conversions->reverse($first->refresh(), '1', 'Dispute refund');

        expect($first->refresh())->status->toBe(ConversionStatus::ClawbackPending)->clawback_amount->toBe('9.0000')
            ->and(ledgerBalance(AccountRef::marketer($marketer->id, WalletBucket::Clawback)))->toBe('-9.00')
            ->and(VendorBalanceItem::query()->where('type', 'marketer_reversal')->sole()->amount)->toBe('10.0000')
            ->and(fn () => app(Withdrawals::class)->request($marketer, $money->of('1.00', $currency)))->toThrow(TransitionRefused::class, 'clawback');

        $second = $conversions->record($invitation, orderOf($this->listing, quantity: 2)->subOrders()->first()->items()->first()->load('subOrder'), 'direct');
        $conversions->approve($second);
        $conversions->release($second->refresh());

        expect($first->refresh())->status->toBe(ConversionStatus::Recovered)->recovered_amount->toBe('9.0000')
            ->and(ledgerBalance(AccountRef::marketer($marketer->id, WalletBucket::Clawback)))->toBe('0.00')
            ->and(ledgerBalance(AccountRef::marketer($marketer->id, WalletBucket::Spendable)))->toBe('9.00');
        assertLedgerBalanced();
    });

    it('mirrors an approved conversion back on reversal', function () {
        [$marketer, $invitation] = joinedAffiliate($this->campaign);
        $conversions = app(Conversions::class);
        $conversion = $conversions->approve($conversions->record($invitation, orderOf($this->listing)->subOrders()->first()->items()->first()->load('subOrder'), 'direct'));

        $conversions->reverse($conversion->refresh());

        expect($conversion->refresh()->status)->toBe(ConversionStatus::Reversed)
            ->and(ledgerBalance(AccountRef::marketer($marketer->id, WalletBucket::Pending)))->toBe('0.00')
            ->and(ledgerBalance(AccountRef::vendor($this->vendor->id)))->toBe('0.00')
            ->and(VendorBalanceItem::query()->whereIn('type', ['marketer_commission', 'marketer_reversal'])->sum('amount'))->toEqual(0);
        assertLedgerBalanced();
    });

    it('includes campaign fees, sample commissions and marketer commissions in the vendor payout net', function () {
        CampaignFeeRule::query()->create(['kind' => 'fixed', 'amount' => '25', 'percent' => '0']);
        $campaign = liveCampaign($this->vendor, sellableListing('105.00', 50, vendor: $this->vendor));
        [, $invitation] = joinedAffiliate($campaign);
        $conversions = app(Conversions::class);
        $order = orderOf($campaign->listing);
        $subOrder = $order->subOrders()->first();
        $conversions->approve($conversions->record($invitation, $subOrder->items()->first()->load('subOrder'), 'direct'));
        $platformSample = $campaign->samples()->where('kind', 'platform')->sole();
        app(Samples::class)->ship($platformSample, ActorType::Admin, 1);
        $subOrder->forceFill(['cod_remittance_confirmed' => true])->save();
        moveSubOrderTo($subOrder, SubOrderStatus::Completed);

        $payout = app(Payouts::class)->generateFor($this->vendor, $this->country, PayoutSchedule::Weekly, CarbonImmutable::now('Asia/Dubai'));
        $lines = collect(app(PayoutStatement::class)->summarize($payout)['lines'])->mapWithKeys(fn ($line) => [$line['label'] => (string) $line['amount']->getAmount()]);
        $settlementNet = (string) SubOrderSettlement::query()->where('sub_order_id', $subOrder->id)->value('net');

        expect($lines['Marketer campaign fees'])->toBe('-25.00')
            ->and($lines['Sample commissions'])->toBe('-10.50')
            ->and($lines['Marketer commissions'])->toBe('-10.00')
            ->and(bccomp((string) $payout->total, bcsub(bcsub(bcsub($settlementNet, '25', 4), '10.5', 4), '10', 4), 4))->toBe(0);
        assertLedgerBalanced();
    });
});

describe('withdrawals', function () {
    it('reserves on request, gives back on reject and pays out on process', function () {
        [$marketer, $invitation] = joinedAffiliate($this->campaign);
        $conversions = app(Conversions::class);
        $conversion = $conversions->approve($conversions->record($invitation, orderOf($this->listing, quantity: 3)->subOrders()->first()->items()->first()->load('subOrder'), 'direct'));
        $conversions->release($conversion->refresh());
        $money = app(MoneyFactory::class);
        $withdrawals = app(Withdrawals::class);

        expect(fn () => $withdrawals->request($marketer, $money->of('30.00', 'AED')))->toThrow(TransitionRefused::class);

        $rejected = $withdrawals->request($marketer, $money->of('20.00', 'AED'));
        expect(ledgerBalance(AccountRef::marketer($marketer->id, WalletBucket::Reserved)))->toBe('20.00');
        $withdrawals->reject($rejected, 1, 'Wrong IBAN');
        expect(ledgerBalance(AccountRef::marketer($marketer->id, WalletBucket::Spendable)))->toBe('27.00');

        $paid = $withdrawals->request($marketer, $money->of('27.00', 'AED'));
        expect(fn () => $withdrawals->process($paid, 1))->toThrow(TransitionRefused::class);
        $withdrawals->approve($paid, 1);
        $withdrawals->process($paid->refresh(), 1);

        expect(ledgerBalance(AccountRef::marketer($marketer->id, WalletBucket::Reserved)))->toBe('0.00')
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::BankClearing)))->toBe('-27.00');
        assertLedgerBalanced();
    });
});

describe('checkout contract gate (FR-CHK-4)', function () {
    it('asks the customer to accept the marketer contract before a credited checkout', function () {
        $template = ContractTemplate::query()->create(['country_id' => $this->country->id, 'kind' => ContractKind::MarketerOnboarding, 'name' => 'Marketer contract', 'required_for_customers' => true]);
        $version = app(ContractPublisher::class)->publish(app(ContractPublisher::class)->draft($template, ['body_ar' => 'عقد', 'body_en' => 'Contract']));
        [, $marketer] = marketerAccount(['affiliate']);
        app(ContractSigner::class)->sign($version, SignerType::Marketer, $marketer->id, $marketer->user_id, 'M');
        $invitation = app(Invitations::class)->joinOpen($this->campaign, $marketer);
        click($invitation->referral_code, 'gate');
        $customer = User::factory()->create(['email_verified_at' => now()]);
        $token = $customer->createToken('t')->plainTextToken;
        $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $this->listing->id, 'quantity' => 1])->assertOk();
        $address = ['name' => 'Sara', 'phone' => '+971500000000', 'city' => 'Dubai', 'area' => 'Marina', 'street' => 'Gate 2'];

        $this->withToken($token)->getJson('/api/v1/uae-en/me/contracts', ['X-Visitor-Token' => 'gate'])->assertOk()->assertJsonPath('data.consent_required', true);
        $this->withToken($token)->postJson('/api/v1/uae-en/orders', ['payment_method' => 'cod', 'address' => $address, 'visitor_token' => 'gate'])
            ->assertStatus(409)->assertJsonPath('error.code', 'customer_contract_required')->assertJsonPath('error.contracts.0.version_id', $version->id);

        $this->getJson("/api/v1/uae-en/contracts/{$version->id}")->assertOk()->assertJsonPath('data.kind', 'marketer_onboarding');
        $this->withToken($token)->postJson("/api/v1/uae-en/contracts/{$version->id}/accept", ['full_name' => 'Sara Ahmed'])->assertUnprocessable();
        $this->withToken($token)->postJson("/api/v1/uae-en/contracts/{$version->id}/accept", ['full_name' => 'Sara Ahmed', 'accept' => true])->assertCreated()->assertJsonStructure(['data' => ['sha256']]);
        $this->withToken($token)->postJson('/api/v1/uae-en/orders', ['payment_method' => 'cod', 'address' => $address, 'visitor_token' => 'gate'])->assertCreated();

        expect(MarketerConversion::query()->count())->toBe(1);
    });

    it('requires a customer purchase contract assigned to the category, but not one of another country', function () {
        $categoryId = $this->listing->variant->product->category_id;
        $ksa = ContractTemplate::query()->create(['country_id' => Country::query()->where('code', 'ksa')->value('id'), 'kind' => ContractKind::CustomerPurchase, 'name' => 'KSA purchase']);
        app(ContractPublisher::class)->publish(app(ContractPublisher::class)->draft($ksa, ['body_ar' => 'شراء']));
        ContractAssignment::query()->create(['contract_template_id' => $ksa->id, 'country_id' => $this->country->id, 'subject_type' => 'category', 'subject_key' => (string) $categoryId]);

        orderOf($this->listing);

        $own = ContractTemplate::query()->create(['country_id' => $this->country->id, 'kind' => ContractKind::CustomerPurchase, 'name' => 'Purchase']);
        app(ContractPublisher::class)->publish(app(ContractPublisher::class)->draft($own, ['body_ar' => 'شراء']));
        ContractAssignment::query()->create(['contract_template_id' => $own->id, 'country_id' => $this->country->id, 'subject_type' => 'category', 'subject_key' => (string) $categoryId]);
        $customer = User::factory()->create(['email_verified_at' => now()]);
        $token = $customer->createToken('t')->plainTextToken;
        $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $this->listing->id, 'quantity' => 1])->assertOk();

        $this->withToken($token)->postJson('/api/v1/uae-en/orders', ['payment_method' => 'cod', 'address' => ['name' => 'S', 'city' => 'Dubai', 'area' => 'M', 'street' => 'G']])
            ->assertStatus(409)->assertJsonPath('error.contracts.0.kind', 'customer_purchase');
    });
});
