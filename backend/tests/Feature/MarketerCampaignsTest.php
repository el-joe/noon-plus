<?php

use App\Domains\Campaigns\Enums\CampaignOrigin;
use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Campaigns\Enums\FeeStatus;
use App\Domains\Campaigns\Enums\InvitationSource;
use App\Domains\Campaigns\Enums\InvitationStatus;
use App\Domains\Campaigns\Enums\SampleStatus;
use App\Domains\Campaigns\Models\CampaignFeeRule;
use App\Domains\Campaigns\Models\CampaignSample;
use App\Domains\Campaigns\Support\CampaignRefused;
use App\Domains\Campaigns\Support\CampaignStateMachine;
use App\Domains\Campaigns\Support\CampaignWizard;
use App\Domains\Campaigns\Support\ContentSubmissions;
use App\Domains\Campaigns\Support\Invitations;
use App\Domains\Campaigns\Support\Samples;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductVariant;
use App\Domains\Contracts\Enums\ContractKind;
use App\Domains\Contracts\Enums\SignerType;
use App\Domains\Contracts\Models\ContractTemplate;
use App\Domains\Contracts\Support\ContractPublisher;
use App\Domains\Contracts\Support\ContractSigner;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Enums\WalletBucket;
use App\Domains\Finance\Models\VendorBalanceItem;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Marketers\Support\CommissionRules;
use App\Domains\Marketers\Support\MarketingSettings;
use App\Domains\Marketers\Support\WhatsAppMessenger;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
});

function signedOnboarding(): void
{
    $template = ContractTemplate::query()->create(['country_id' => app(CountryContext::class)->current()->id, 'kind' => ContractKind::MarketerOnboarding, 'name' => 'Marketer contract']);
    app(ContractPublisher::class)->publish(app(ContractPublisher::class)->draft($template, ['body_ar' => 'عقد']));
}

describe('wizard rules', function () {
    it('quotes the fee as fixed + variable per category × fulfilment, without VAT', function () {
        $parent = Category::factory()->create();
        $category = Category::factory()->childOf($parent)->create();
        $listing = sellableListing('105.00', 50, ['fulfilment' => 'fbn', 'product_variant_id' => ProductVariant::factory()->for(Product::factory()->state(['category_id' => $category->id]))], $this->vendor);
        CampaignFeeRule::query()->create(['kind' => 'fixed', 'amount' => '50', 'percent' => '2']);
        CampaignFeeRule::query()->create(['kind' => 'variable', 'category_id' => $parent->id, 'fulfilment' => null, 'amount' => '5', 'percent' => '1']);
        CampaignFeeRule::query()->create(['kind' => 'variable', 'category_id' => $parent->id, 'fulfilment' => 'fbn', 'amount' => '10', 'percent' => '5']);
        CampaignFeeRule::query()->create(['kind' => 'variable', 'category_id' => $parent->id, 'fulfilment' => 'fbm', 'amount' => '99', 'percent' => '9']);

        $quote = app(CampaignWizard::class)->quoteFor($listing->load('variant.product.category'), '1000', []);

        expect($quote)->fixed_fee->toBe('70.00')->variable_fee->toBe('60.00')->fee->toBe('130.00')->grand_total->toBe('1130.00');

        [, $influencer] = marketerAccount(['influencer']);
        $unlimited = app(CampaignWizard::class)->quoteFor($listing, null, [$influencer->id]);
        expect($unlimited)->base->toBe('200.00')->fee->toBe('74.00'); // 50 + 4 + 10 + 10
    });

    it('needs a budget strictly greater than the influencers\' fixed total', function () {
        [, $a] = marketerAccount(['influencer']);
        [, $b] = marketerAccount(['influencer']);

        expect(fn () => liveCampaign($this->vendor, data: ['budget' => '400', 'influencer_ids' => [$a->id, $b->id]], live: false))
            ->toThrow(CampaignRefused::class, 'greater than');

        $campaign = liveCampaign($this->vendor, data: ['budget' => '400.01', 'influencer_ids' => [$a->id, $b->id]], live: false);
        expect($campaign->influencer_fixed_reserved)->toBe('400.0000')->and($campaign->status)->toBe(CampaignStatus::PendingAdmin);
    });

    it('needs an end date for an unlimited budget', function () {
        expect(fn () => liveCampaign($this->vendor, data: ['budget' => null], live: false))->toThrow(CampaignRefused::class, 'end date');

        $campaign = liveCampaign($this->vendor, data: ['budget' => '0', 'ends_at' => now()->addMonth()->toDateTimeString()], live: false);
        expect($campaign->budget)->toBeNull()->and($campaign->ends_at)->not->toBeNull();
    });

    it('checks stock after samples against the category minimum and the allowed fulfilment', function () {
        [, $influencer] = marketerAccount(['influencer']);
        $tight = sellableListing('105.00', 11, vendor: $this->vendor);

        expect(fn () => liveCampaign($this->vendor, $tight, ['influencer_ids' => [$influencer->id], 'open_to_affiliates' => false], false))->toThrow(CampaignRefused::class, 'at least 10');

        app(InventoryLedger::class)->move($tight, 1, 'restock');
        expect(liveCampaign($this->vendor, $tight, ['influencer_ids' => [$influencer->id], 'open_to_affiliates' => false], false)->exists)->toBeTrue();

        $crossDock = sellableListing('105.00', 50, ['fulfilment' => 'cross_dock'], $this->vendor);
        expect(fn () => liveCampaign($this->vendor, $crossDock, live: false))->toThrow(CampaignRefused::class, 'fulfilment');
    });

    it('refuses another store\'s listing, inactive influencers and travel campaigns', function () {
        $other = sellableListing('105.00', 50);
        [, $pending] = marketerAccount(['influencer'], 'pending');

        expect(fn () => liveCampaign($this->vendor, $other, live: false))->toThrow(CampaignRefused::class)
            ->and(fn () => liveCampaign($this->vendor, data: ['influencer_ids' => [$pending->id]], live: false))->toThrow(CampaignRefused::class)
            ->and(fn () => app(CampaignWizard::class)->create(CampaignOrigin::TravelAgency, ['listing_id' => $other->id, 'title' => 'Trip'], null, null))->toThrow(CampaignRefused::class);
    });

    it('needs the collaboration contract signed for the picked marketer types', function () {
        $template = ContractTemplate::query()->create(['country_id' => $this->country->id, 'kind' => ContractKind::SellerMarketer, 'name' => 'Collab', 'destinations' => ['affiliate']]);
        $version = app(ContractPublisher::class)->publish(app(ContractPublisher::class)->draft($template, ['body_ar' => 'تعاون']));

        try {
            liveCampaign($this->vendor, live: false);
            $this->fail('expected a contract refusal');
        } catch (CampaignRefused $refused) {
            expect($refused->errorCode)->toBe('contract_required');
        }

        app(ContractSigner::class)->sign($version, SignerType::Vendor, $this->vendor->id, 1, 'Owner');
        expect(liveCampaign($this->vendor, live: false)->exists)->toBeTrue();
    });
});

describe('lifecycle and fees', function () {
    it('goes live on approval: dispatches invitations and charges the fee to the next statement', function () {
        CampaignFeeRule::query()->create(['kind' => 'fixed', 'amount' => '30', 'percent' => '0']);
        [, $influencer] = marketerAccount(['influencer']);

        $campaign = liveCampaign($this->vendor, data: ['influencer_ids' => [$influencer->id]]);
        $invitation = $campaign->invitations()->sole();

        expect($campaign->status)->toBe(CampaignStatus::Active)
            ->and($campaign->fee_status)->toBe(FeeStatus::Charged)
            ->and($invitation)->status->toBe(InvitationStatus::Pending)->influencer_fixed->toBe('200.0000')->influencer_fixed_status->toBe('reserved')
            ->and(strlen($invitation->referral_code))->toBe(10)
            ->and(DB::table('referral_codes')->where('code', $invitation->referral_code)->value('invitation_id'))->toBe($invitation->id)
            ->and($invitation->expires_at->diffInHours(now(), true))->toBeGreaterThan(11.9)
            ->and(VendorBalanceItem::query()->where('type', 'campaign_fee')->sole()->amount)->toBe('-30.0000')
            ->and(ledgerBalance(AccountRef::vendor($this->vendor->id)))->toBe('-30.00')
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::FeeRevenue)))->toBe('30.00')
            ->and(WhatsAppMessenger::sent())->toHaveCount(1)
            ->and(WhatsAppMessenger::sent()[0]['message'])->toContain('signature=')
            ->and(CampaignSample::query()->where('kind', 'influencer')->count())->toBe(1);
        assertLedgerBalanced();
    });

    it('voids a quoted fee and releases everything when rejected', function () {
        CampaignFeeRule::query()->create(['kind' => 'fixed', 'amount' => '30', 'percent' => '0']);
        [, $influencer] = marketerAccount(['influencer']);
        $campaign = liveCampaign($this->vendor, data: ['influencer_ids' => [$influencer->id]], live: false);

        expect(fn () => app(CampaignStateMachine::class)->reject($campaign, ActorType::Admin, 1, ' '))->toThrow(TransitionRefused::class);
        app(CampaignStateMachine::class)->reject($campaign, ActorType::Admin, 1, 'Wrong category');

        expect($campaign->refresh())->status->toBe(CampaignStatus::Rejected)->fee_status->toBe(FeeStatus::Voided)->influencer_fixed_reserved->toBe('0.0000')
            ->and($campaign->samples()->where('status', '!=', SampleStatus::Cancelled)->count())->toBe(0)
            ->and(VendorBalanceItem::query()->count())->toBe(0);
    });

    it('applies the campaign fee refund policy on cancel-active', function (string $policy, ?string $refund) {
        CampaignFeeRule::query()->create(['kind' => 'fixed', 'amount' => '40', 'percent' => '0']);
        app(MarketingSettings::class)->set('campaign_fee_refund_policy', $policy);
        $campaign = liveCampaign($this->vendor, data: ['budget' => null, 'ends_at' => now()->addDays(10)->setTimezone('Asia/Dubai')->toDateTimeString()]);

        $this->travel(5)->days();
        app(CampaignStateMachine::class)->cancel($campaign, ActorType::Vendor, 1);

        $line = VendorBalanceItem::query()->where('type', 'campaign_fee_refund')->first();
        expect($line?->amount)->toBe($refund)
            ->and($campaign->refresh()->fee_status)->toBe($refund === null ? FeeStatus::Charged : FeeStatus::Refunded);
        assertLedgerBalanced();
    })->with([
        'none (default)' => ['none', null],
        'before first conversion' => ['before_first_conversion', '40.0000'],
        'pro rata' => ['pro_rata', '20.0000'],
    ]);

    it('auto-approves after the configured hours', function () {
        $campaign = liveCampaign($this->vendor, live: false);

        $this->travel(35)->hours();
        $this->artisan('nawy:campaigns:auto-approve')->assertSuccessful();
        expect($campaign->refresh()->status)->toBe(CampaignStatus::PendingAdmin);

        $this->travel(2)->hours();
        $this->artisan('nawy:campaigns:auto-approve')->assertSuccessful();
        expect($campaign->refresh()->status)->toBe(CampaignStatus::AutoApproved)->and($campaign->live_at)->not->toBeNull();
    });

    it('pauses on low stock, resumes when stock is back and completes at zero', function () {
        $listing = sellableListing('105.00', 15, vendor: $this->vendor);
        $campaign = liveCampaign($this->vendor, $listing, ['open_to_affiliates' => false]);
        $ledger = app(InventoryLedger::class);

        $ledger->move($listing, -8, 'damaged');
        $this->artisan('nawy:campaigns:monitor-stock')->assertSuccessful();
        expect($campaign->refresh()->status)->toBe(CampaignStatus::Paused)->and($campaign->owner_paused_at)->toBeNull();

        $ledger->move($listing, 10, 'restock');
        $this->artisan('nawy:campaigns:monitor-stock');
        expect($campaign->refresh()->status)->toBe(CampaignStatus::Active);

        app(CampaignStateMachine::class)->pause($campaign, ActorType::Vendor, 1);
        $this->artisan('nawy:campaigns:monitor-stock');
        expect($campaign->refresh()->status)->toBe(CampaignStatus::Paused);

        $ledger->move($listing, -$ledger->available($listing), 'lost');
        $this->artisan('nawy:campaigns:monitor-stock');
        expect($campaign->refresh()->status)->toBe(CampaignStatus::Done);
    });

    it('completes ended campaigns and settles accepted influencers net of the fee, once', function () {
        [, $influencer] = marketerAccount(['influencer']);
        $campaign = liveCampaign($this->vendor, data: ['influencer_ids' => [$influencer->id], 'budget' => null, 'ends_at' => now()->addDays(3)->setTimezone('Asia/Dubai')->toDateTimeString()]);
        app(Invitations::class)->accept($campaign->invitations()->sole());

        $this->travel(4)->days();
        $this->artisan('nawy:campaigns:complete-ended')->assertSuccessful();
        app(CampaignStateMachine::class)->settleInfluencers($campaign->refresh());

        expect($campaign->status)->toBe(CampaignStatus::Done)
            ->and($campaign->influencers_settled_at)->not->toBeNull()
            ->and(ledgerBalance(AccountRef::marketer($influencer->id, WalletBucket::Spendable)))->toBe('180.00')
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::FeeRevenue)))->toBe('20.00')
            ->and(VendorBalanceItem::query()->where('type', 'marketer_commission')->sole()->amount)->toBe('-200.0000')
            ->and($campaign->invitations()->sole()->influencer_fixed_status)->toBe('settled');
        assertLedgerBalanced();
    });

    it('does not pay influencers of a cancelled campaign', function () {
        [, $influencer] = marketerAccount(['influencer']);
        $campaign = liveCampaign($this->vendor, data: ['influencer_ids' => [$influencer->id]]);
        app(Invitations::class)->accept($campaign->invitations()->sole());

        app(CampaignStateMachine::class)->cancel($campaign, ActorType::Vendor, 1);

        expect(ledgerBalance(AccountRef::marketer($influencer->id)))->toBe('0.00')
            ->and($campaign->invitations()->sole()->status)->toBe(InvitationStatus::Cancelled)
            ->and($campaign->refresh()->influencer_fixed_reserved)->toBe('0.0000');
    });
});

describe('invitations', function () {
    it('accepts through the signed WhatsApp link without signing in, and refuses a tampered link', function () {
        [, $influencer] = marketerAccount(['influencer']);
        $invitation = liveCampaign($this->vendor, data: ['influencer_ids' => [$influencer->id]])->invitations()->sole();
        $links = app(Invitations::class)->signedLinks($invitation);

        $this->get($links['accept'])->assertOk()->assertSee('Accept invitation');
        $this->get($links['accept'].'x')->assertForbidden();
        $this->post(str_replace('answer=accept', 'answer=decline', $links['accept']))->assertForbidden();

        $this->post($links['accept'])->assertOk()->assertSee('Accepted');
        expect($invitation->refresh()->status)->toBe(InvitationStatus::Accepted);

        $this->travel(13)->hours();
        $this->get($links['decline'])->assertForbidden();
    });

    it('needs an active marketer who accepted the current onboarding contract (FR-MKT-1)', function () {
        signedOnboarding();
        [$user, $influencer] = marketerAccount(['influencer']);
        $invitation = liveCampaign($this->vendor, data: ['influencer_ids' => [$influencer->id]])->invitations()->sole();

        expect(fn () => app(Invitations::class)->accept($invitation))->toThrow(TransitionRefused::class, 'contract');

        $this->actingAs($user)->get(onPanel('marketer', 'contract'))->assertOk()->assertSee('عقد');
        $this->actingAs($user)->post(onPanel('marketer', 'contract'), ['full_name' => 'Omar K', 'accept' => '1'])->assertRedirect();
        $influencer->forceFill(['global_status' => 'suspended'])->save();
        expect(fn () => app(Invitations::class)->accept($invitation->refresh()))->toThrow(TransitionRefused::class, 'active');

        $influencer->forceFill(['global_status' => 'active'])->save();
        expect(app(Invitations::class)->accept($invitation->refresh())->status)->toBe(InvitationStatus::Accepted);
    });

    it('times out after 12 hours and replaces the influencer fairly, handing over the sample', function () {
        [, $first] = marketerAccount(['influencer']);
        [, $busy] = marketerAccount(['influencer']);
        [, $fresh] = marketerAccount(['influencer']);
        [, $affiliate] = marketerAccount(['affiliate']);
        $other = liveCampaign(Vendor::factory()->create(), data: ['influencer_ids' => [$busy->id]]);
        app(Invitations::class)->accept($other->invitations()->sole());
        $campaign = liveCampaign($this->vendor, data: ['influencer_ids' => [$first->id], 'budget' => '1000']);
        $invitation = $campaign->invitations()->sole();
        $sample = CampaignSample::query()->where('invitation_id', $invitation->id)->sole();

        $this->travel(11)->hours();
        $this->artisan('nawy:campaigns:expire-invitations');
        expect($invitation->refresh()->status)->toBe(InvitationStatus::Pending);

        $this->travel(2)->hours();
        $this->artisan('nawy:campaigns:expire-invitations')->assertSuccessful();

        $replacement = $campaign->invitations()->where('id', '!=', $invitation->id)->sole();
        expect($invitation->refresh())->status->toBe(InvitationStatus::TimedOut)->influencer_fixed_status->toBe('released')->replaced_at->not->toBeNull()
            ->and($replacement)->marketer_profile_id->toBe($fresh->id)->source->toBe(InvitationSource::Replacement)->replaced_invitation_id->toBe($invitation->id)
            ->and($sample->refresh()->invitation_id)->toBe($replacement->id)
            ->and($campaign->refresh()->influencer_fixed_reserved)->toBe('200.0000');
    });

    it('only replaces an influencer whose own fixed amount fits the remaining budget', function () {
        [, $a] = marketerAccount(['influencer']);
        [, $b] = marketerAccount(['influencer']);
        [, $expensive] = marketerAccount(['influencer']);
        app(CommissionRules::class)->save($expensive->id, null, ['influencer_fixed' => '300']);
        $campaign = liveCampaign($this->vendor, data: ['influencer_ids' => [$a->id, $b->id], 'budget' => '450']);

        app(Invitations::class)->reject($campaign->invitations()->where('marketer_profile_id', $a->id)->sole(), null, 'No time');

        expect($campaign->invitations()->where('marketer_profile_id', $expensive->id)->exists())->toBeFalse()
            ->and($campaign->invitations()->count())->toBe(2)
            ->and($campaign->refresh()->influencer_fixed_reserved)->toBe('200.0000');
    });

    it('does not replace affiliates of an open campaign; affiliates and brokers join it (origin F)', function () {
        [, $affiliate] = marketerAccount(['affiliate']);
        [, $broker] = marketerAccount(['affiliate', 'broker']);
        [, $influencerOnly] = marketerAccount(['influencer']);
        $campaign = liveCampaign($this->vendor);

        $joined = app(Invitations::class)->joinOpen($campaign, $broker);
        expect($joined)->status->toBe(InvitationStatus::Accepted)->effective_type->toBe('broker')->source->toBe(InvitationSource::OpenJoin)
            ->and(fn () => app(Invitations::class)->joinOpen($campaign, $broker))->toThrow(TransitionRefused::class)
            ->and(fn () => app(Invitations::class)->joinOpen($campaign, $influencerOnly))->toThrow(TransitionRefused::class);

        $closed = liveCampaign($this->vendor, data: ['open_to_affiliates' => false]);
        expect(fn () => app(Invitations::class)->joinOpen($closed, $affiliate))->toThrow(TransitionRefused::class);

        $pending = app(Invitations::class)->dispatch($campaign, $affiliate, InvitationSource::InviteMore);
        app(Invitations::class)->reject($pending);
        expect($campaign->invitations()->count())->toBe(2);
    });

    it('lets the admin resend WhatsApp only after the cooldown', function () {
        [, $influencer] = marketerAccount(['influencer']);
        $invitation = liveCampaign($this->vendor, data: ['influencer_ids' => [$influencer->id]])->invitations()->sole();

        expect(fn () => app(Invitations::class)->resend($invitation))->toThrow(TransitionRefused::class);
        $this->travel(11)->minutes();
        app(Invitations::class)->resend($invitation->refresh());
        expect(WhatsAppMessenger::sent())->toHaveCount(2);
    });
});

describe('other origins', function () {
    it('handles a marketer request (D): the store sets a budget and it goes live with the requester accepted', function () {
        CampaignFeeRule::query()->create(['kind' => 'fixed', 'amount' => '0', 'percent' => '10']);
        [, $affiliate] = marketerAccount(['affiliate']);
        $listing = sellableListing('105.00', 30, vendor: $this->vendor);

        $campaign = app(CampaignWizard::class)->requestByMarketer($affiliate, $listing, 'Let me sell it');
        expect($campaign->status)->toBe(CampaignStatus::MarketerRequested)
            ->and(fn () => app(CampaignWizard::class)->approveRequest($campaign, '0'))->toThrow(CampaignRefused::class);

        app(CampaignWizard::class)->approveRequest($campaign, '500');

        expect($campaign->refresh())->status->toBe(CampaignStatus::Active)->fee_amount->toBe('50.0000')->fee_status->toBe(FeeStatus::Charged)
            ->and($campaign->invitations()->sole())->status->toBe(InvitationStatus::Accepted)->source->toBe(InvitationSource::Request);
    });

    it('allows one peer promotion (E) per promoter and owner per month, between influencers on the owner\'s own FBN listing', function () {
        [, $owner] = marketerAccount(['influencer']);
        $ownStore = Vendor::factory()->create();
        $owner->forceFill(['vendor_id' => $ownStore->id])->save();
        [, $promoter] = marketerAccount(['influencer']);
        [, $affiliate] = marketerAccount(['affiliate']);
        $fbn = sellableListing('105.00', 5, ['fulfilment' => 'fbn'], $ownStore);
        $fbm = sellableListing('105.00', 5, ['fulfilment' => 'fbm'], $ownStore);
        $wizard = app(CampaignWizard::class);

        $campaign = $wizard->peerPromotion($promoter, $fbn);
        expect($campaign)->status->toBe(CampaignStatus::Active)->fee_status->toBe(FeeStatus::None)->vendor_id->toBe($ownStore->id)
            ->and($campaign->invitations()->sole())->status->toBe(InvitationStatus::Accepted)->source->toBe(InvitationSource::Peer)
            ->and(fn () => $wizard->peerPromotion($promoter, $fbn))->toThrow(CampaignRefused::class, 'this month')
            ->and(fn () => $wizard->peerPromotion($owner, $fbn))->toThrow(CampaignRefused::class)
            ->and(fn () => $wizard->peerPromotion($affiliate, $fbn))->toThrow(CampaignRefused::class)
            ->and(fn () => $wizard->peerPromotion($promoter, $fbm))->toThrow(CampaignRefused::class);

        $this->travelTo(now()->addMonthNoOverflow()->startOfMonth()->addDays(2));
        expect($wizard->peerPromotion($promoter, $fbn)->exists)->toBeTrue();
    });
});

describe('samples and content', function () {
    it('ships a sample out of stock with a sample commission on the store statement', function () {
        [$user, $influencer] = marketerAccount(['influencer']);
        $listing = sellableListing('105.00', 30, vendor: $this->vendor);
        $campaign = liveCampaign($this->vendor, $listing, ['influencer_ids' => [$influencer->id]]);
        $sample = CampaignSample::query()->where('kind', 'influencer')->sole();
        $samples = app(Samples::class);

        expect(fn () => $samples->request($sample, 999, ['city' => 'Dubai']))->toThrow(NotFoundHttpException::class);
        $samples->request($sample, $influencer->id, ['name' => 'Omar', 'city' => 'Dubai']);
        $samples->ship($sample->refresh(), ActorType::Admin, 1, 'TRK1');

        expect($sample->refresh())->status->toBe(SampleStatus::Shipped)->commission_amount->toBe('10.5000')
            ->and(app(InventoryLedger::class)->available($listing))->toBe(29)
            ->and(VendorBalanceItem::query()->where('type', 'sample_commission')->sole()->amount)->toBe('-10.5000');

        $samples->deliver($sample, ActorType::Admin, 1);
        $samples->confirmReceipt($sample->refresh(), $influencer->id);
        expect($sample->refresh()->status)->toBe(SampleStatus::ReviewDue)->and($sample->review_due_at)->not->toBeNull();

        $samples->markReturned($sample, ActorType::Admin, 1);
        expect(app(InventoryLedger::class)->available($listing))->toBe(30)->and($campaign->exists)->toBeTrue();
        assertLedgerBalanced();
    });

    it('reviews content submissions of accepted marketers, a rejection needs a reason', function () {
        [, $affiliate] = marketerAccount(['affiliate']);
        $invitation = app(Invitations::class)->joinOpen(liveCampaign($this->vendor), $affiliate);
        $content = app(ContentSubmissions::class);

        $submission = $content->submit($invitation, 'instagram', 'https://instagram.com/p/1');
        expect(fn () => $content->reject($submission, ActorType::Vendor, 1, ''))->toThrow(TransitionRefused::class);
        expect($content->approve($submission, ActorType::Vendor, 1)->status->value)->toBe('approved');

        $pending = app(Invitations::class)->dispatch($invitation->campaign, marketerAccount(['affiliate'])[1], InvitationSource::InviteMore);
        expect(fn () => $content->submit($pending, 'tiktok', 'https://tiktok.com/x'))->toThrow(TransitionRefused::class);
    });
});
