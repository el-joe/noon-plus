<?php

use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Campaigns\Models\CampaignInvitation;
use App\Domains\Campaigns\Models\MarketerCampaign;
use App\Domains\Campaigns\Support\CampaignWizard;
use App\Domains\Campaigns\Support\Invitations;
use App\Domains\Catalog\Models\Category;
use App\Domains\Contracts\Enums\ContractKind;
use App\Domains\Contracts\Models\ContractSignature;
use App\Domains\Contracts\Models\ContractTemplate;
use App\Domains\Contracts\Support\ContractPublisher;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Documents\Enums\DocumentOwner;
use App\Domains\Documents\Enums\DocumentStatus;
use App\Domains\Documents\Models\Document;
use App\Domains\Documents\Models\DocumentType;
use App\Domains\Documents\Support\Documents;
use App\Domains\Listings\Support\ListingSubmissionGates;
use App\Domains\Marketers\Enums\MarketerStatus;
use App\Domains\Marketers\Models\CrossPromoParticipation;
use App\Domains\Marketers\Models\CrossPromoPolicy;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Marketers\Support\CrossPromotion;
use App\Domains\Marketers\Support\MarketerProfiles;
use App\Domains\Marketers\Support\MarketingSettings;
use App\Domains\Marketers\Support\WhatsAppMessenger;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
    Cache::flush();
    Storage::fake('local');
    WhatsAppMessenger::fake();
    marketerDefaults(['influencer_fixed' => '200', 'affiliate_percent' => '10', 'fee_percent' => '10']);
});

/**
 * Creator Studio user: marketer membership in the UAE (country-bound).
 *
 * @return array{0: User, 1: MarketerProfile}
 */
function studioMarketer(array $types = ['affiliate']): array
{
    [$user, $marketer] = marketerAccount($types);
    $user->memberships()->update(['country_id' => app(CountryContext::class)->current()->id]);

    return [$user, $marketer];
}

describe('Creator Studio', function () {
    it('renders every page for the marketer and scopes invitations to them', function () {
        [$user, $marketer] = studioMarketer(['influencer', 'affiliate']);
        [$vendorUser, $vendor] = sellerAccount('uae');
        $campaign = liveCampaign($vendor, data: ['influencer_ids' => [$marketer->id]]);
        $mine = $campaign->invitations()->sole();
        [, $other] = marketerAccount(['affiliate']);
        $theirs = app(Invitations::class)->joinOpen($campaign, $other);

        foreach (['', 'invitations', 'campaigns', 'marketplace', 'links', 'wallet', 'cross-promotion', 'contract'] as $page) {
            $this->actingAs($user)->get(onPanel('marketer', $page))->assertOk();
        }

        $this->actingAs($user)->get(onPanel('marketer', 'invitations'))->assertSee($campaign->title);
        $this->actingAs($user)->post(onPanel('marketer', "invitations/{$theirs->id}/accept"))->assertNotFound();
        $this->actingAs($user)->post(onPanel('marketer', "invitations/{$mine->id}/accept"))->assertRedirect();
        $this->actingAs($user)->get(onPanel('marketer', "campaigns/{$mine->id}"))->assertOk()->assertSee($mine->referral_code)->assertSee('Sample tracker');
        $this->actingAs($user)->get(onPanel('marketer', "campaigns/{$theirs->id}"))->assertNotFound();

        expect($mine->refresh()->status->value)->toBe('accepted')->and($vendorUser->exists)->toBeTrue();
    });

    it('lets an affiliate join an open campaign and request a withdrawal from the panel', function () {
        [$user] = studioMarketer(['affiliate']);
        $campaign = liveCampaign(Vendor::factory()->create());

        $this->actingAs($user)->get(onPanel('marketer', 'marketplace'))->assertSee($campaign->title);
        $this->actingAs($user)->post(onPanel('marketer', "marketplace/{$campaign->id}/join"))->assertRedirect();
        $this->actingAs($user)->post(onPanel('marketer', 'wallet/withdrawals'), ['amount' => '5', 'iban' => 'AE07033', 'account_name' => 'Omar'])
            ->assertSessionHasErrors('action');

        expect(CampaignInvitation::query()->where('campaign_id', $campaign->id)->sole()->status->value)->toBe('accepted');
    });

    it('lets an agency user switch only to the marketers it manages', function () {
        [$owner] = [User::factory()->create()];
        $agency = app(MarketerProfiles::class)->createAgency($owner, 'Star Agency');
        [, $managed] = marketerAccount(['influencer']);
        [, $outsider] = marketerAccount(['influencer']);
        app(MarketerProfiles::class)->attachToAgency($managed, $agency);

        $this->actingAs($owner)->get(onPanel('marketer'))->assertOk()->assertSee($managed->display_name);
        $this->actingAs($owner)->post(onPanel('marketer', 'agency/switch'), ['marketer_id' => $outsider->id])->assertNotFound();
        $this->actingAs($owner)->post(onPanel('marketer', 'agency/switch'), ['marketer_id' => $managed->id])->assertRedirect();
    });
});

describe('Seller Center', function () {
    it('creates a campaign through the wizard with a live fee quote, scoped to the store', function () {
        [$user, $vendor] = sellerAccount('uae');
        $listing = sellableListing('105.00', 40, vendor: $vendor);
        [, $influencer] = marketerAccount(['influencer']);
        [$otherUser, $otherVendor] = sellerAccount('uae');
        $foreign = liveCampaign($otherVendor);

        $this->actingAs($user)->get(onPanel('partner', 'campaigns/create'))->assertOk()->assertSee($influencer->display_name);
        $this->actingAs($user)->postJson(onPanel('partner', 'campaigns/quote'), ['listing_id' => $listing->id, 'budget' => '1000', 'influencer_ids' => [$influencer->id]])
            ->assertOk()->assertJsonPath('data.influencer_fixed_total', '200.00');
        $this->actingAs($user)->postJson(onPanel('partner', 'campaigns/quote'), ['listing_id' => $foreign->listing_id])->assertNotFound();

        $this->actingAs($user)->post(onPanel('partner', 'campaigns'), ['listing_id' => $listing->id, 'title' => 'Ramadan', 'budget' => '150', 'influencer_ids' => [$influencer->id]])
            ->assertSessionHasErrors('budget');
        $this->actingAs($user)->post(onPanel('partner', 'campaigns'), ['listing_id' => $listing->id, 'title' => 'Ramadan', 'budget' => '1000', 'influencer_ids' => [$influencer->id]])
            ->assertRedirect();

        $campaign = MarketerCampaign::query()->where('vendor_id', $vendor->id)->sole();
        $this->actingAs($user)->get(onPanel('partner', 'campaigns'))->assertSee('Ramadan')->assertDontSee($foreign->title);
        $this->actingAs($user)->get(onPanel('partner', "campaigns/{$campaign->id}"))->assertOk();
        $this->actingAs($user)->get(onPanel('partner', "campaigns/{$foreign->id}"))->assertNotFound();
        $this->actingAs($user)->post(onPanel('partner', "campaigns/{$foreign->id}/cancel"))->assertNotFound();
        $this->actingAs($user)->post(onPanel('partner', "campaigns/{$campaign->id}/cancel"))->assertRedirect();

        expect($campaign->refresh()->status)->toBe(CampaignStatus::Cancelled)->and($foreign->refresh()->status)->toBe(CampaignStatus::Active)->and($otherUser->exists)->toBeTrue();
    });

    it('blocks the store behind the click-wrap Seller Agreement until it signs on the Contracts page', function () {
        [$user, $vendor] = sellerAccount('uae');
        $template = ContractTemplate::query()->create(['country_id' => $this->country->id, 'kind' => ContractKind::SellerPlatform, 'name' => 'Seller Agreement']);
        $version = app(ContractPublisher::class)->publish(app(ContractPublisher::class)->draft($template, ['body_ar' => 'اتفاقية {store_name}', 'body_en' => 'Agreement for {store_name}']));

        $this->actingAs($user)->get(onPanel('partner', 'products'))->assertRedirect(onPanel('partner', 'contracts'));
        $this->actingAs($user)->get(onPanel('partner', 'contracts'))->assertOk()->assertSee('Read and sign');
        $this->actingAs($user)->get(onPanel('partner', "contracts/{$version->id}"))->assertOk()->assertSee('Agreement for '.$vendor->store_name);
        $this->actingAs($user)->post(onPanel('partner', "contracts/{$version->id}/sign"), ['full_name' => 'Owner'])->assertSessionHasErrors('accept');
        $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->post(onPanel('partner', "contracts/{$version->id}/sign"), ['full_name' => 'Owner Name', 'accept' => '1'])->assertRedirect(onPanel('partner', 'contracts'));

        $signature = ContractSignature::query()->sole();
        expect($signature)->ip_address->toBe('198.51.100.7')->signer_user_id->toBe($user->id)->kind->toBe(ContractKind::SellerPlatform);
        $this->actingAs($user)->get(onPanel('partner', 'products'))->assertOk();
        $this->actingAs($user)->get(onPanel('partner', "contracts/signatures/{$signature->id}/download"))->assertOk();

        [$otherUser] = sellerAccount('uae');
        $this->actingAs($otherUser)->get(onPanel('partner', "contracts/signatures/{$signature->id}/download"))->assertNotFound();

        $ksa = ContractTemplate::query()->create(['country_id' => Country::query()->where('code', 'ksa')->value('id'), 'kind' => ContractKind::SellerPlatform, 'name' => 'KSA']);
        $ksaVersion = app(ContractPublisher::class)->publish(app(ContractPublisher::class)->draft($ksa, ['body_ar' => 'ك']));
        $this->actingAs($user)->get(onPanel('partner', "contracts/{$ksaVersion->id}"))->assertNotFound();
    });
});

describe('documents', function () {
    it('applies the requirement matrix per business type and category', function () {
        $category = Category::factory()->create();
        DocumentType::query()->create(['country_id' => $this->country->id, 'code' => 'trade_licence', 'name_en' => 'Trade licence', 'name_ar' => 'رخصة', 'business_type' => 'vendor', 'requirement' => 'mandatory']);
        DocumentType::query()->create(['country_id' => $this->country->id, 'code' => 'vat', 'name_en' => 'VAT certificate', 'name_ar' => 'ضريبة', 'business_type' => 'vendor', 'requirement' => 'optional']);
        DocumentType::query()->create(['country_id' => $this->country->id, 'code' => 'vat', 'name_en' => 'VAT certificate', 'name_ar' => 'ضريبة', 'business_type' => 'vendor', 'category_id' => $category->id, 'requirement' => 'not_applicable']);
        DocumentType::query()->create(['country_id' => Country::query()->where('code', 'ksa')->value('id'), 'code' => 'cr', 'name_en' => 'CR', 'name_ar' => 'سجل', 'business_type' => 'vendor', 'requirement' => 'mandatory']);
        $documents = app(Documents::class);

        expect($documents->requirements(DocumentOwner::Vendor)->pluck('code')->all())->toBe(['trade_licence', 'vat'])
            ->and($documents->requirements(DocumentOwner::Vendor, $category->id)->pluck('code')->all())->toBe(['trade_licence'])
            ->and($documents->missingMandatory(DocumentOwner::Vendor, 'vendor', 1)->pluck('code')->all())->toBe(['trade_licence']);
    });

    it('uploads to the private disk, reviews with a reason and clears the certification gate', function () {
        [$user, $vendor] = sellerAccount('uae');
        $category = Category::factory()->create(['requires_certification' => true]);
        $type = DocumentType::query()->create(['country_id' => $this->country->id, 'code' => 'halal', 'name_en' => 'Halal certificate', 'name_ar' => 'حلال', 'business_type' => 'certification', 'requirement' => 'mandatory']);
        $admin = adminAccount('uae', 'vendor_relations');
        $gates = app(ListingSubmissionGates::class);

        $this->actingAs($user)->post(onPanel('partner', 'documents'), ['document_type_id' => $type->id, 'category_id' => $category->id, 'file' => UploadedFile::fake()->create('halal.pdf', 100, 'application/pdf')])->assertRedirect();
        $document = Document::query()->sole();
        Storage::disk('local')->assertExists($document->file_path);
        expect($gates->hasApprovedCertification($vendor, $category))->toBeFalse();

        $this->actingAs($admin)->get(onPanel('admin', 'documents'))->assertOk()->assertSee('Halal certificate');
        $this->actingAs($admin)->post(onPanel('admin', "documents/{$document->id}/review"), ['decision' => 'reject'])->assertSessionHasErrors('action');
        $this->actingAs($admin)->post(onPanel('admin', "documents/{$document->id}/review"), ['decision' => 'approve'])->assertRedirect();

        expect($document->refresh()->status)->toBe(DocumentStatus::Approved)
            ->and($gates->hasApprovedCertification($vendor, Category::factory()->childOf($category)->create()))->toBeTrue();

        $document->forceFill(['valid_until' => now()->subDay()->toDateString()])->save();
        $this->artisan('nawy:documents:expire')->assertSuccessful();
        expect($document->refresh()->status)->toBe(DocumentStatus::Expired)->and($gates->hasApprovedCertification($vendor, $category))->toBeFalse();

        [$otherUser] = sellerAccount('uae');
        $this->actingAs($otherUser)->get(onPanel('partner', "documents/{$document->id}/download"))->assertNotFound();
        $this->actingAs(adminAccount('uae', 'marketing'))->get(onPanel('admin', 'documents'))->assertForbidden();
    });
});

describe('admin authorization', function () {
    it('gives marketing the marketer screens and keeps support out', function () {
        $marketing = adminAccount('uae', 'marketing');
        $support = adminAccount('uae', 'support');
        [, $marketer] = marketerAccount(['affiliate'], 'pending');

        foreach (['marketers', 'marketers/settings', 'marketers/campaigns', 'marketers/cross-promotion', 'marketers/withdrawals'] as $page) {
            $this->actingAs($marketing)->get(onPanel('admin', $page))->assertOk();
            $this->actingAs($support)->get(onPanel('admin', $page))->assertForbidden();
        }

        $this->actingAs($marketing)->post(onPanel('admin', "marketers/{$marketer->id}/status"), ['status' => 'active'])->assertRedirect();
        $this->actingAs($marketing)->post(onPanel('admin', 'marketers/settings/rules'), ['affiliate_percent' => '12'])->assertRedirect();
        $this->actingAs($marketing)->post(onPanel('admin', 'marketers/settings/general'), ['invitation_timeout_hours' => 6, 'auto_approve_hours' => 24, 'attribution_window_days' => 10, 'referral_grace_days' => 2, 'clearing_days' => 7, 'min_stock_for_campaign' => 5, 'attribution_model' => 'linear', 'campaign_fee_refund_policy' => 'pro_rata', 'cross_promotion_enabled' => '1'])->assertRedirect();
        $this->actingAs($marketing)->get(onPanel('admin', 'contracts'))->assertForbidden();

        expect($marketer->refresh()->global_status)->toBe(MarketerStatus::Active)
            ->and(app(MarketingSettings::class)->get('attribution_model'))->toBe('linear')
            ->and(app(MarketingSettings::class)->int('invitation_timeout_hours'))->toBe(6);
    });

    it('lets vendor relations author contracts for their country only; legacy templates need a super admin', function () {
        $relations = adminAccount('uae', 'vendor_relations');
        $super = adminAccount();

        $this->actingAs($relations)->post(onPanel('admin', 'contracts'), ['name' => 'Agreement', 'kind' => 'seller_platform', 'mode' => 'editor', 'consent_mode' => 'click_wrap'])->assertRedirect();
        $template = ContractTemplate::query()->sole();
        expect($template->country_id)->toBe($this->country->id);

        $this->actingAs($relations)->post(onPanel('admin', "contracts/{$template->id}/draft"), ['body_ar' => 'نص', 'body_en' => 'Text'])->assertRedirect();
        $this->actingAs($relations)->post(onPanel('admin', 'contracts/versions/'.$template->versions()->sole()->id.'/publish'))->assertRedirect();
        $this->actingAs($relations)->get(onPanel('admin', "contracts/{$template->id}"))->assertOk()->assertSee('v1');
        $this->actingAs($relations)->post(onPanel('admin', 'contracts'), ['name' => 'Global', 'kind' => 'seller_platform', 'mode' => 'editor', 'consent_mode' => 'click_wrap', 'global' => '1'])->assertForbidden();
        $this->actingAs($super)->post(onPanel('admin', 'contracts'), ['name' => 'Global', 'kind' => 'seller_platform', 'mode' => 'editor', 'consent_mode' => 'click_wrap', 'global' => '1'])->assertRedirect();

        $ksaAdmin = adminAccount('ksa', 'vendor_relations');
        $this->actingAs($ksaAdmin)->get(onPanel('admin', "contracts/{$template->id}?country=ksa"))->assertNotFound();
        expect($template->versions()->sole()->status->value)->toBe('published');
    });
});

describe('public API and isolation', function () {
    it('lists only active creators of the market and shows the boutique picks', function () {
        [, $active] = marketerAccount(['influencer'], 'active', ['followers' => 5000]);
        [, $pending] = marketerAccount(['affiliate'], 'pending');
        [, $affiliate] = marketerAccount(['affiliate']);
        $campaign = liveCampaign(Vendor::factory()->create());
        $invitation = app(Invitations::class)->joinOpen($campaign, $affiliate);

        $this->getJson('/api/v1/uae-en/creators')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.slug', $active->slug);
        $this->getJson('/api/v1/uae-en/creators?type=affiliate')->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/uae-en/creators/{$pending->slug}")->assertNotFound();
        $this->getJson("/api/v1/uae-en/creators/{$affiliate->slug}")->assertOk()->assertJsonPath('data.picks.0.referral_code', $invitation->referral_code);

        $this->getJson('/api/v1/ksa-en/creators')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/ksa-en/referrals/{$invitation->referral_code}")->assertNotFound();
    });
});

describe('cross-promotion engine', function () {
    it('evaluates monthly obligations with eligibility, exemptions and penalties', function () {
        app(MarketingSettings::class)->set('cross_promotion_enabled', true);
        [, $owner] = marketerAccount(['influencer']);
        $store = Vendor::factory()->create();
        $owner->forceFill(['vendor_id' => $store->id])->save();
        [, $doer] = marketerAccount(['influencer']);
        [, $slacker] = marketerAccount(['influencer']);
        [, $exempt] = marketerAccount(['influencer']);
        [, $affiliate] = marketerAccount(['affiliate']);
        $policy = CrossPromoPolicy::query()->create([
            'name' => 'One peer promotion a month', 'type' => 'peer_promotion', 'is_active' => true,
            'eligibility' => ['job_types' => ['influencer']], 'obligation' => ['activity' => 'peer_promotion', 'count' => 1],
            'penalty' => ['action' => 'suspend'], 'exemptions' => [$exempt->id],
        ]);
        app(CampaignWizard::class)->peerPromotion($doer, sellableListing('105.00', 5, ['fulfilment' => 'fbn'], $store));
        $period = now('Asia/Dubai')->format('Y-m');

        expect(app(CrossPromotion::class)->dashboard($doer)->first())->completed->toBe(1)->status->toBe('met');

        app(CrossPromotion::class)->evaluate($period, 1);
        $statuses = CrossPromoParticipation::query()->get()->mapWithKeys(fn ($row) => [$row->marketer_profile_id => $row->status])->all();

        expect($statuses[$doer->id])->toBe('met')
            ->and($statuses[$slacker->id])->toBe('missed')
            ->and($statuses[$exempt->id])->toBe('exempt')
            ->and($statuses)->not->toHaveKey($affiliate->id)
            ->and($slacker->refresh()->global_status)->toBe(MarketerStatus::Suspended)
            ->and(app(CrossPromotion::class)->evaluate($period, 1))->toBe(0);
        expect($policy->exists)->toBeTrue();
    });

    it('does nothing while cross-promotion is disabled in the country', function () {
        CrossPromoPolicy::query()->create(['name' => 'P', 'type' => 'obligation', 'obligation' => ['activity' => 'accepted_campaign', 'count' => 1]]);
        marketerAccount(['influencer']);

        expect(app(CrossPromotion::class)->evaluate(now()->format('Y-m')))->toBe(0);
    });
});
