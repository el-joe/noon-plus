<?php

use App\Domains\Advertising\Enums\AdBookingStatus;
use App\Domains\Advertising\Enums\AdCampaignStatus;
use App\Domains\Advertising\Enums\ParticipationInvitationStatus;
use App\Domains\Advertising\Enums\ParticipationRequestStatus;
use App\Domains\Advertising\Enums\SubscriptionStatus;
use App\Domains\Advertising\Models\AdCampaign;
use App\Domains\Advertising\Models\AdEvent;
use App\Domains\Advertising\Models\AdPackage;
use App\Domains\Advertising\Models\AdSlot;
use App\Domains\Advertising\Support\AdBookings;
use App\Domains\Advertising\Support\AdCampaigns;
use App\Domains\Advertising\Support\AdPackageSubscriptions;
use App\Domains\Advertising\Support\AdvertiserBilling;
use App\Domains\Advertising\Support\CouponParticipations;
use App\Domains\Advertising\Support\SponsoredRanker;
use App\Domains\Catalog\Models\Category;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Enums\WalletBucket;
use App\Domains\Finance\Models\VendorBalanceItem;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Finance\Support\Ledger;
use App\Domains\Finance\Support\Posting;
use App\Domains\Listings\Models\Listing;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Money\MoneyFactory;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->country = $this->actAsCountry('uae');
    Storage::fake('public');
    Storage::fake('local');
});

/**
 * An approved sponsored-product campaign for a listing (new store unless given).
 *
 * @param  array<string, mixed>  $data
 */
function liveAdCampaign(?Listing $listing = null, array $data = []): AdCampaign
{
    $listing ??= sellableListing('50.00');
    $campaigns = app(AdCampaigns::class);
    $campaign = $campaigns->create($listing->vendor, $data + ['listing_id' => $listing->id, 'pricing_model' => 'cpc', 'bid' => '2.00', 'daily_budget' => '10.00'], 1);
    $campaigns->submit($campaign, 1);

    return $campaigns->approve($campaign->refresh(), 1)->refresh();
}

/**
 * Give a marketer spendable wallet money (released commission).
 */
function fundMarketer(int $marketerProfileId, string $amount): void
{
    app(Ledger::class)->post('test-fund:'.$marketerProfileId.':'.fake()->uuid(), 'test', (new Posting)
        ->debit(AccountRef::platform(LedgerAccountCode::PromoExpense), app(MoneyFactory::class)->of($amount, 'AED'))
        ->credit(AccountRef::marketer($marketerProfileId, WalletBucket::Spendable), app(MoneyFactory::class)->of($amount, 'AED')));
}

describe('sponsored ranking', function () {
    it('interleaves labelled ads at the configured slots without duplicating the organic result', function () {
        $category = Category::factory()->create(['slug' => 'phones']);
        $organic = collect(range(1, 8))->map(fn (int $i): Listing => sellableListing((string) (100 + $i)));
        $organic->each(fn (Listing $listing) => $listing->variant->product->update(['category_id' => $category->id]));
        $high = liveAdCampaign($organic[7], ['bid' => '5.00', 'category_ids' => [$category->id]]);
        $low = liveAdCampaign(null, ['bid' => '1.00', 'category_ids' => [$category->id]]);
        liveAdCampaign(null, ['bid' => '9.00', 'category_ids' => [Category::factory()->create()->id]]);

        $items = $this->getJson('/api/v1/uae-en/products?category=phones&per_page=24')->assertOk()->json('data');

        expect(count($items))->toBe(9)
            ->and($items[0])->toMatchArray(['slug' => $high->listing->variant->product->slug, 'sponsored' => true, 'label' => 'Ad'])
            ->and($items[5])->toMatchArray(['slug' => $low->listing->variant->product->slug, 'sponsored' => true])
            ->and(collect($items)->where('slug', $high->listing->variant->product->slug)->count())->toBe(1)
            ->and(collect($items)->where('sponsored', false)->count())->toBe(7)
            ->and($items[0]['ad_token'])->not->toBeNull();
        expect(AdEvent::query()->where('type', 'impression')->count())->toBe(2);
    });

    it('matches keywords against the search text and ranks CPC by bid × CTR against CPM', function () {
        $phone = sellableListing('80.00');
        $phone->variant->product->update(['title_en' => 'Galaxy phone case']);
        $keyword = liveAdCampaign(null, ['bid' => '1.00', 'keywords' => ['case']]);
        $cpm = liveAdCampaign(null, ['pricing_model' => 'cpm', 'bid' => '30.00', 'keywords' => ['case']]);
        liveAdCampaign(null, ['bid' => '50.00', 'keywords' => ['laptop']]);

        $ranked = app(SponsoredRanker::class)->ranked(['q' => 'phone case']);

        expect($ranked->map(fn (array $ad): int => $ad['campaign']->id)->all())->toBe([$cpm->id, $keyword->id]);
        $keyword->forceFill(['clicks' => 50, 'impressions' => 100])->save();
        expect(app(SponsoredRanker::class)->ranked(['q' => 'phone case'])->first()['campaign']->id)->toBe($keyword->id);
    });
});

describe('ad campaign budget pacing', function () {
    it('bills clicks until the daily budget, rejects duplicates, then resumes and bills the store at the daily reset', function () {
        $campaign = liveAdCampaign(null, ['bid' => '3.00', 'daily_budget' => '10.00', 'total_budget' => '15.00']);
        $campaigns = app(AdCampaigns::class);

        expect($campaigns->click($campaign, 'search', 'v1')->is_billable)->toBeTrue()
            ->and($campaigns->click($campaign, 'search', 'v1')->invalid_reason)->toBe('duplicate');
        $campaigns->click($campaign, 'search', 'v2');
        $campaigns->click($campaign, 'search', 'v3');
        $over = $campaigns->click($campaign, 'search', 'v4');

        expect($over->is_billable)->toBeFalse()->and($over->invalid_reason)->toBe('daily_budget')
            ->and($campaign->refresh()->status)->toBe(AdCampaignStatus::BudgetExhausted)
            ->and((string) $campaign->spent_today)->toBe('9.0000')
            ->and($campaign->clicks)->toBe(3);
        expect($campaigns->click($campaign, 'search', 'v5')->invalid_reason)->toBe('not_active');

        $this->travel(1)->days();
        $this->artisan('nawy:ads:reset-daily-budgets')->assertSuccessful();

        expect($campaign->refresh())->status->toBe(AdCampaignStatus::Active)->and((string) $campaign->spent_today)->toBe('0.0000')
            ->and(VendorBalanceItem::query()->where('type', VendorBalanceItem::TYPE_AD_SPEND)->sole()->amount)->toBe('-9.0000')
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::AdRevenue)))->toBe('9.00');

        $campaigns->click($campaign, 'search', 'v6');
        $campaigns->click($campaign, 'search', 'v7');
        expect($campaigns->click($campaign, 'search', 'v8')->invalid_reason)->toBe('total_budget');
        expect($campaign->refresh())->status->toBe(AdCampaignStatus::BudgetExhausted)->total_exhausted->toBeTrue();
        $this->travel(1)->days();
        $this->artisan('nawy:ads:reset-daily-budgets')->assertSuccessful();
        expect($campaign->refresh()->status)->toBe(AdCampaignStatus::BudgetExhausted)
            ->and(VendorBalanceItem::query()->where('type', VendorBalanceItem::TYPE_AD_SPEND)->count())->toBe(2);
        assertLedgerBalanced();
    });

    it('charges CPM per thousand impressions and validates bids and budgets at submission', function () {
        $campaign = liveAdCampaign(null, ['pricing_model' => 'cpm', 'bid' => '20.00', 'daily_budget' => '10.00']);
        foreach (range(1, 3) as $i) {
            app(AdCampaigns::class)->impression($campaign, 'category', "visitor-{$i}");
        }
        expect((string) $campaign->refresh()->spent_today)->toBe('0.0600');

        $listing = sellableListing();
        $low = app(AdCampaigns::class)->create($listing->vendor, ['listing_id' => $listing->id, 'bid' => '0.01', 'daily_budget' => '10'], 1);
        expect(fn () => app(AdCampaigns::class)->submit($low, 1))->toThrow(TransitionRefused::class, 'bid must be at least');
        $foreign = sellableListing();
        expect(fn () => app(AdCampaigns::class)->create($listing->vendor, ['listing_id' => $foreign->id, 'bid' => '1', 'daily_budget' => '10'], 1))->toThrow(TransitionRefused::class);
    });

    it('records a click from the sponsored token and refuses a forged one', function () {
        $listing = sellableListing();
        $campaign = liveAdCampaign($listing, ['keywords' => ['shoe']]);
        $listing->variant->product->update(['title_en' => 'Running shoe']);
        $token = $this->getJson('/api/v1/uae-en/products?q=shoe')->json('data.0.ad_token');

        $this->postJson('/api/v1/uae-en/ads/click', ['token' => $token], ['X-Visitor-Token' => 'abc'])->assertOk()->assertJsonPath('data.billable', true);
        $this->postJson('/api/v1/uae-en/ads/click', ['token' => 'forged'])->assertStatus(422);
        expect($campaign->refresh()->clicks)->toBe(1);
    });
});

describe('ad slot bookings', function () {
    it('charges the store, respects slot capacity per day, refunds a rejection and goes live on schedule', function () {
        $slot = AdSlot::query()->create(['code' => 'home-hero', 'name' => 'Homepage hero', 'placement' => 'homepage', 'price_per_day' => '100.00', 'max_concurrent' => 1]);
        $vendor = Vendor::factory()->create();
        $other = Vendor::factory()->create();
        $bookings = app(AdBookings::class);

        $first = $bookings->draft($slot, AdvertiserBilling::VENDOR, $vendor->id, ['starts_on' => now()->addDays(2)->toDateString(), 'ends_on' => now()->addDays(4)->toDateString(), 'link_url' => 'https://nawy.test/x'], UploadedFile::fake()->image('hero.jpg'), 1);
        $bookings->submit($first, 1);
        expect((string) $first->refresh()->amount)->toBe('300.0000')
            ->and(ledgerBalance(AccountRef::vendor($vendor->id)))->toBe('-300.00');

        $clash = $bookings->draft($slot, AdvertiserBilling::VENDOR, $other->id, ['starts_on' => now()->addDays(4)->toDateString(), 'ends_on' => now()->addDays(6)->toDateString()], UploadedFile::fake()->image('b.jpg'), 1);
        expect(fn () => $bookings->submit($clash, 1))->toThrow(TransitionRefused::class, 'fully booked');
        expect(ledgerBalance(AccountRef::vendor($other->id)))->toBe('0.00');

        $bookings->reject($first->refresh(), 1, 'Creative has text overlay');
        expect($first->refresh())->status->toBe(AdBookingStatus::Rejected)->payment_status->toBe('refunded')
            ->and(ledgerBalance(AccountRef::vendor($vendor->id)))->toBe('0.00');

        $bookings->submit($clash->refresh(), 1);
        $bookings->approve($clash->refresh(), 1);
        $this->artisan('nawy:ads:schedule-bookings')->assertSuccessful();
        expect($clash->refresh()->status)->toBe(AdBookingStatus::Scheduled);
        $this->travel(4)->days();
        $this->artisan('nawy:ads:schedule-bookings')->assertSuccessful();
        $this->getJson('/api/v1/uae-en/ads/placements/homepage')->assertOk()->assertJsonPath('data.0.label', 'Ad')->assertJsonPath('data.0.sponsored', true);
        $this->travel(3)->days();
        $this->artisan('nawy:ads:schedule-bookings')->assertSuccessful();
        expect($clash->refresh()->status)->toBe(AdBookingStatus::Completed);
        assertLedgerBalanced();
    });

    it('lets a marketer book from the wallet only when it covers the price, and expires unapproved bookings with a refund', function () {
        $slot = AdSlot::query()->create(['code' => 'cat-banner', 'name' => 'Category banner', 'placement' => 'category', 'price_per_day' => '50.00', 'max_concurrent' => 2]);
        [, $marketer] = marketerAccount(['influencer']);
        $bookings = app(AdBookings::class);
        $booking = $bookings->draft($slot, AdvertiserBilling::MARKETER, $marketer->id, ['starts_on' => now()->addDay()->toDateString(), 'ends_on' => now()->addDay()->toDateString()], UploadedFile::fake()->image('m.jpg'), 1);

        expect(fn () => $bookings->submit($booking, 1))->toThrow(TransitionRefused::class, 'wallet does not cover');
        fundMarketer($marketer->id, '60.00');
        $bookings->submit($booking->refresh(), 1);
        expect(ledgerBalance(AccountRef::marketer($marketer->id)))->toBe('10.00');

        $this->travel(1)->days();
        $this->artisan('nawy:ads:schedule-bookings')->assertSuccessful();
        expect($booking->refresh()->status)->toBe(AdBookingStatus::Expired)
            ->and(ledgerBalance(AccountRef::marketer($marketer->id)))->toBe('60.00');
    });
});

describe('ad packages', function () {
    it('activates a wallet subscription at once with VAT, waits for approval on bank transfer and expires', function () {
        $package = AdPackage::query()->create(['code' => 'pro', 'name_en' => 'Pro', 'name_ar' => 'برو', 'price' => '105.00', 'duration_days' => 30, 'features' => ['featured_profile']]);
        [, $marketer] = marketerAccount(['broker']);
        fundMarketer($marketer->id, '200.00');
        $subscriptions = app(AdPackageSubscriptions::class);

        $wallet = $subscriptions->subscribe($package, $marketer->id, 'wallet', null, 1);
        expect($wallet->status)->toBe(SubscriptionStatus::Active)
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::AdRevenue)))->toBe('100.00')
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::VatPayable)))->toBe('5.00')
            ->and($subscriptions->features($marketer->id))->toBe(['featured_profile']);
        expect(fn () => $subscriptions->subscribe($package, $marketer->id, 'wallet', null, 1))->toThrow(TransitionRefused::class, 'already have');

        [, $other] = marketerAccount(['affiliate']);
        expect(fn () => $subscriptions->subscribe($package, $other->id, 'bank_transfer', null, 1))->toThrow(TransitionRefused::class);
        $bank = $subscriptions->subscribe($package, $other->id, 'bank_transfer', UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf'), 1);
        expect($bank->status)->toBe(SubscriptionStatus::Pending);
        $subscriptions->approve($bank, 1);
        expect($bank->refresh()->status)->toBe(SubscriptionStatus::Active);

        $this->travel(31)->days();
        $this->artisan('nawy:ad-packages:expire')->assertSuccessful();
        expect($wallet->refresh()->status)->toBe(SubscriptionStatus::Expired);
        assertLedgerBalanced();
    });
});

describe('coupon participation', function () {
    it('charges approved participants, refunds rejected paid ones, closes on time and activates the coupon for participants only', function () {
        $coupon = Coupon::factory()->create(['code' => 'WHITEFRIDAY', 'scope' => 'platform', 'type' => 'percentage', 'value' => '10', 'is_active' => false]);
        $participations = app(CouponParticipations::class);
        $invitation = $participations->create($coupon, ['title' => 'White Friday', 'min_fee' => '50.00', 'capacity' => 2, 'closes_at' => now()->addDay()->toDateTimeString()], 1);
        $participations->open($invitation, 1);
        $vendor = Vendor::factory()->create();
        $late = Vendor::factory()->create();
        [, $marketer] = marketerAccount(['influencer']);

        expect(fn () => $participations->request($invitation->refresh(), 'vendor', $vendor->id, '40', 'wallet', null, 1))->toThrow(TransitionRefused::class, 'Offer at least');
        $vendorRequest = $participations->request($invitation->refresh(), 'vendor', $vendor->id, '75', 'wallet', null, 1);
        $marketerRequest = $participations->request($invitation->refresh(), 'marketer', $marketer->id, '50', 'wallet', null, 1);
        $participations->approve($vendorRequest, 1);

        expect($vendorRequest->refresh()->status)->toBe(ParticipationRequestStatus::Paid)
            ->and(VendorBalanceItem::query()->where('type', VendorBalanceItem::TYPE_COUPON_PARTICIPATION)->sole()->amount)->toBe('-75.0000');
        expect(fn () => $participations->approve($marketerRequest, 1))->toThrow(TransitionRefused::class, 'wallet does not cover');
        fundMarketer($marketer->id, '50.00');
        $participations->approve($marketerRequest->refresh(), 1);
        expect($invitation->refresh()->status)->toBe(ParticipationInvitationStatus::Closed);
        expect(fn () => $participations->request($invitation->refresh(), 'vendor', $late->id, '90', 'wallet', null, 1))->toThrow(TransitionRefused::class, 'closed');

        $participations->reject($marketerRequest->refresh(), 1, 'Brand clash');
        expect($marketerRequest->refresh()->status)->toBe(ParticipationRequestStatus::Refunded)
            ->and(ledgerBalance(AccountRef::marketer($marketer->id)))->toBe('50.00')
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::AdRevenue)))->toBe('75.00');

        $this->artisan('nawy:coupons:activate-participation-invitations')->assertSuccessful();
        expect($invitation->refresh()->status)->toBe(ParticipationInvitationStatus::Active)->and($coupon->refresh()->is_active)->toBeTrue()
            ->and($participations->restrictedVendorIds($coupon))->toBe([$vendor->id]);

        [$customer, $token] = customerWithToken();
        $theirs = sellableListing('100.00', vendor: $vendor);
        $outsider = sellableListing('100.00');
        $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $theirs->id, 'quantity' => 1])->assertOk();
        $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $outsider->id, 'quantity' => 1])->assertOk();
        $this->withToken($token)->postJson('/api/v1/uae-en/cart/coupons', ['code' => 'WHITEFRIDAY'])->assertOk()
            ->assertJsonPath('data.totals.discount.amount', '10.00');
        assertLedgerBalanced();
    });

    it('closes open invitations when their time passes and ends them without paid participants; bank transfers wait for the proof', function () {
        $coupon = Coupon::factory()->create(['code' => 'EID', 'scope' => 'platform', 'type' => 'fixed_amount', 'value' => '5', 'is_active' => false]);
        $participations = app(CouponParticipations::class);
        $invitation = $participations->create($coupon, ['title' => 'Eid', 'min_fee' => '10.00', 'capacity' => 5, 'closes_at' => now()->addMinutes(20)->toDateTimeString()], 1);
        $participations->open($invitation, 1);
        $request = $participations->request($invitation->refresh(), 'vendor', Vendor::factory()->create()->id, '10', 'bank_transfer', UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'), 1);
        $participations->approve($request, 1);
        expect($request->refresh()->status)->toBe(ParticipationRequestStatus::ApprovedPendingPayment);

        $this->travel(30)->minutes();
        $this->artisan('nawy:coupons:close-participation-invitations')->assertSuccessful();
        expect($invitation->refresh()->status)->toBe(ParticipationInvitationStatus::Closed);
        $this->artisan('nawy:coupons:activate-participation-invitations')->assertSuccessful();
        expect($invitation->refresh()->status)->toBe(ParticipationInvitationStatus::Ended)->and($coupon->refresh()->is_active)->toBeFalse();
    });
});
