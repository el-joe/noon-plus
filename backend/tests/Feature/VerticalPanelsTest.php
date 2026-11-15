<?php

use App\Domains\Advertising\Enums\AdCampaignStatus;
use App\Domains\Advertising\Enums\ParticipationRequestStatus;
use App\Domains\Advertising\Models\AdCampaign;
use App\Domains\Advertising\Models\CouponParticipationInvitation;
use App\Domains\Advertising\Models\CouponParticipationRequest;
use App\Domains\Advertising\Support\AdCampaigns;
use App\Domains\Advertising\Support\CouponParticipations;
use App\Domains\Classifieds\Enums\ClassifiedAdStatus;
use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Classifieds\Models\ClassifiedCategory;
use App\Domains\Classifieds\Models\ClassifiedReport;
use App\Domains\Classifieds\Support\ClassifiedAdMachine;
use App\Domains\Classifieds\Support\ClassifiedAds;
use App\Domains\Classifieds\Support\ClassifiedInquiries;
use App\Domains\Classifieds\Support\ClassifiedReports;
use App\Domains\Countries\Models\Country;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Panels\Support\PanelCountries;
use App\Domains\RealEstate\Enums\LeadStatus;
use App\Domains\RealEstate\Support\BrokerLeads;
use App\Domains\RealEstate\Support\SpecialRequests;
use App\Domains\Travel\Enums\BookingStatus;
use App\Domains\Travel\Enums\PackageStatus;
use App\Domains\Travel\Models\TravelPackage;
use App\Domains\Travel\Support\TravelBookings;
use App\Domains\Travel\Support\TravelPackages;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
    Storage::fake('public');
    Storage::fake('local');
    $this->category = ClassifiedCategory::query()->create(['slug' => 'furniture', 'name_en' => 'Furniture', 'name_ar' => 'أثاث']);
});

/**
 * A Seller Center user bound to a classified shop.
 *
 * @return array{0: User, 1: Vendor}
 */
function classifiedShop(): array
{
    [$user, $shop] = sellerAccount('uae', ['type' => 'classified', 'verified_at' => now()]);
    $user->memberships()->update(['role' => MembershipRole::ClassifiedSeller]);

    return [$user, $shop];
}

/**
 * A Travel Partner user bound to a travel agency in the UAE.
 *
 * @return array{0: User, 1: Vendor}
 */
function travelAgencyAccount(): array
{
    $user = User::factory()->create();
    $agency = Vendor::factory()->create(['type' => 'travel', 'owner_user_id' => $user->id]);
    $user->memberships()->create(['role' => MembershipRole::TravelAgency, 'country_id' => Country::query()->where('code', 'uae')->value('id'), 'tenant_type' => PanelCountries::VENDOR_TENANT, 'tenant_id' => $agency->id]);

    return [$user, $agency];
}

function shopAd(User $owner, Vendor $shop, bool $live = true): ClassifiedAd
{
    $ad = app(ClassifiedAds::class)->create($owner->id, ['category_id' => test()->category->id, 'title' => 'L-shaped sofa grey', 'price' => '1450'], ActorType::Vendor, $shop);
    app(ClassifiedAds::class)->addPhoto($ad, UploadedFile::fake()->image('sofa.jpg'));
    app(ClassifiedAdMachine::class)->submit($ad->refresh(), ActorType::Vendor, $owner->id);

    return $live ? app(ClassifiedAdMachine::class)->approve($ad->refresh(), 1)->refresh() : $ad->refresh();
}

describe('Marketplace (classified shop) pages', function () {
    it('posts an ad from the panel, lists it with stats, answers inquiries and marks it sold', function () {
        [$user, $shop] = classifiedShop();

        $this->actingAs($user)->get(onPanel('partner', 'marketplace/ads/create?category='.$this->category->id))->assertOk()->assertSee('Submit for review');
        $this->actingAs($user)->post(onPanel('partner', 'marketplace/ads'), [
            'category_id' => $this->category->id, 'kind' => 'offer', 'title' => 'Oak dining table', 'price' => '900', 'city' => 'Dubai',
            'photos' => [UploadedFile::fake()->image('t.jpg')], 'submit' => '1',
        ])->assertRedirect();
        $draft = ClassifiedAd::query()->sole();
        expect($draft)->status->toBe(ClassifiedAdStatus::PendingReview)->vendor_id->toBe($shop->id);

        $live = shopAd($user, $shop);
        [$buyer] = customerWithToken();
        $thread = app(ClassifiedInquiries::class)->start($live->load('category'), $buyer, 'Can you deliver?', ['name' => 'Buyer', 'email' => 'buyer@example.com', 'phone' => '0501234567']);

        $this->actingAs($user)->get(onPanel('partner', 'marketplace/ads'))->assertOk()->assertSee('Oak dining table')->assertSee('L-shaped sofa grey')->assertSee('Chats started');
        $this->actingAs($user)->get(onPanel('partner', 'marketplace/inquiries'))->assertOk()->assertSee('Can you deliver?');
        $this->actingAs($user)->post(onPanel('partner', "marketplace/inquiries/{$thread->id}/reply"), ['body' => 'Yes, free in Dubai'])->assertRedirect();
        expect($thread->refresh()->status->value)->toBe('contacted');

        $this->actingAs($user)->get(onPanel('partner', "marketplace/ads/{$live->id}/sold"))->assertOk()->assertSee('Who brought the buyer?');
        $this->actingAs($user)->post(onPanel('partner', "marketplace/ads/{$live->id}/sold"), ['final_price' => '1300'])->assertRedirect(onPanel('partner', 'marketplace/ads'));
        expect($live->refresh()->status)->toBe(ClassifiedAdStatus::Sold);
    });

    it('keeps each shop to its own ads and product stores out of Marketplace', function () {
        [$user] = classifiedShop();
        [$otherUser, $otherShop] = classifiedShop();
        $theirs = shopAd($otherUser, $otherShop);
        [$productSeller] = sellerAccount('uae');

        $this->actingAs($user)->get(onPanel('partner', "marketplace/ads/{$theirs->id}/sold"))->assertNotFound();
        $this->actingAs($user)->post(onPanel('partner', "marketplace/ads/{$theirs->id}/pause"))->assertNotFound();
        $this->actingAs($productSeller)->get(onPanel('partner', 'marketplace/ads'))->assertForbidden();
    });
});

describe('Seller advertising page', function () {
    it('creates a sponsored campaign for review and joins an open coupon invitation', function () {
        [$user, $vendor] = sellerAccount('uae');
        $listing = sellableListing('60.00', vendor: $vendor);
        $coupon = Coupon::factory()->create(['code' => 'SUMMER', 'scope' => 'platform', 'type' => 'percentage', 'value' => '10']);
        $invitation = app(CouponParticipations::class)->create($coupon, ['title' => 'Summer', 'min_fee' => '20', 'capacity' => 3, 'closes_at' => now()->addDay()->toDateTimeString()], 1);
        app(CouponParticipations::class)->open($invitation, 1);

        $this->actingAs($user)->get(onPanel('partner', 'advertising'))->assertOk()->assertSee('Summer');
        $this->actingAs($user)->post(onPanel('partner', 'advertising/campaigns'), ['listing_id' => $listing->id, 'pricing_model' => 'cpc', 'bid' => '1.50', 'daily_budget' => '25', 'keywords' => 'shoes, sneakers'])->assertRedirect();
        $this->actingAs($user)->post(onPanel('partner', "advertising/coupons/{$invitation->id}/join"), ['offered_fee' => '25', 'payment_method' => 'wallet'])->assertRedirect();

        expect(AdCampaign::query()->sole())->status->toBe(AdCampaignStatus::PendingReview)->keywords->toBe(['shoes', 'sneakers'])
            ->and(CouponParticipationRequest::query()->sole()->participant_id)->toBe($vendor->id);
    });
});

describe('Broker pages', function () {
    it('shows the pipeline, moves leads and claims special requests, for brokers only', function () {
        [$user, $broker] = marketerAccount(['broker']);
        $lead = app(BrokerLeads::class)->manual($broker->id, ['name' => 'Khalid A.', 'budget' => '650000']);
        $special = app(SpecialRequests::class)->create(User::factory()->create()->id, ['details' => 'Looking for a villa in Arabian Ranches', 'city' => 'Dubai']);

        $this->actingAs($user)->get(onPanel('marketer', 'broker/leads'))->assertOk()->assertSee('Khalid A.')->assertSee('Leads pipeline');
        $this->actingAs($user)->post(onPanel('marketer', "broker/leads/{$lead->id}/status"), ['status' => 'contacted'])->assertRedirect();
        $this->actingAs($user)->post(onPanel('marketer', "broker/leads/{$lead->id}/follow-ups"), ['channel' => 'call', 'due_at' => now()->addHour()->toDateTimeString()])->assertRedirect();
        $this->actingAs($user)->get(onPanel('marketer', 'broker/special-requests'))->assertOk()->assertSee($special->number);
        $this->actingAs($user)->post(onPanel('marketer', "broker/special-requests/{$special->id}/claim"))->assertRedirect();
        $this->actingAs($user)->get(onPanel('marketer', 'broker/properties'))->assertOk();

        expect($lead->refresh()->status)->toBe(LeadStatus::Contacted)
            ->and($lead->next_follow_up_at)->not->toBeNull()
            ->and($special->refresh()->claimed_by_marketer_profile_id)->toBe($broker->id);

        [$otherUser] = marketerAccount(['broker']);
        $this->actingAs($otherUser)->post(onPanel('marketer', "broker/leads/{$lead->id}/status"), ['status' => 'negotiating'])->assertNotFound();
        [$affiliateUser] = marketerAccount(['affiliate']);
        $this->actingAs($affiliateUser)->get(onPanel('marketer', 'broker/leads'))->assertForbidden();
    });
});

describe('Travel Partner pages', function () {
    it('drafts a package with departures, submits it, and shows capacity with held and booked seats', function () {
        [$user, $agency] = travelAgencyAccount();

        $this->actingAs($user)->get(onPanel('travel-agency'))->assertOk()->assertSee('Live packages');
        $this->actingAs($user)->post(onPanel('travel-agency', 'packages'), [
            'title_en' => 'Umrah 7 nights 5 star', 'title_ar' => 'عمرة', 'trip_type' => 'umrah', 'destination_city' => 'Makkah', 'nights' => 7,
            'itinerary_text' => "Arrival\nUmrah\nMadinah", 'inclusions_text' => "Flights\nHotel", 'policy' => [['days_before' => 14, 'refund_percent' => 100]],
        ])->assertRedirect();
        $package = TravelPackage::query()->sole();
        expect($package->itinerary)->toHaveCount(3)->and($package->cancellation_policy)->toBe([['days_before' => 14, 'refund_percent' => 100]]);

        $this->actingAs($user)->post(onPanel('travel-agency', "packages/{$package->id}/departures"), [
            'departs_on' => now()->addDays(20)->toDateString(), 'returns_on' => now()->addDays(27)->toDateString(), 'seat_capacity' => 40, 'rooms' => ['quad' => '245', 'double' => '320', 'single' => ''],
        ])->assertRedirect();
        $this->actingAs($user)->put(onPanel('travel-agency', "packages/{$package->id}"), [
            'title_en' => 'Umrah 7 nights 5 star', 'title_ar' => 'عمرة', 'trip_type' => 'umrah', 'destination_city' => 'Makkah', 'nights' => 7,
            'itinerary_text' => "Arrival\nUmrah", 'inclusions_text' => 'Flights', 'contract' => UploadedFile::fake()->create('contract.pdf', 20, 'application/pdf'),
        ])->assertRedirect();
        $this->actingAs($user)->post(onPanel('travel-agency', "packages/{$package->id}/submit"))->assertRedirect();
        expect($package->refresh()->status)->toBe(PackageStatus::PendingReview);

        $admin = adminAccount();
        $this->actingAs($admin)->get(onPanel('admin', 'travel'))->assertOk()->assertSee('Umrah 7 nights 5 star');
        $this->actingAs($admin)->get(onPanel('admin', "travel/{$package->id}/contract"))->assertOk();
        $this->actingAs($admin)->post(onPanel('admin', "travel/{$package->id}/approve"))->assertRedirect();
        expect($package->refresh()->status)->toBe(PackageStatus::Active);

        $departure = $package->departures()->sole();
        app(TravelBookings::class)->request($departure, null, [['room_type' => 'quad', 'travellers' => 2]], ['name' => 'Guest', 'email' => 'g@example.com', 'phone' => '0501234567']);
        $this->actingAs($user)->get(onPanel('travel-agency', 'departures'))->assertOk()->assertSee('Departures & capacity')->assertSee('Quad 245');
        $this->actingAs($user)->post(onPanel('travel-agency', "departures/{$departure->id}/capacity"), ['seat_capacity' => 1])->assertSessionHasErrors('action');
    });

    it('shows booking requests with contact details, confirms or rejects them, and keeps agencies apart', function () {
        [$user, $agency] = travelAgencyAccount();
        [$otherUser] = travelAgencyAccount();
        $packages = app(TravelPackages::class);
        $package = $packages->create($agency, ['title_en' => 'Baku trip', 'title_ar' => 'باكو', 'destination_city' => 'Baku', 'nights' => 4, 'itinerary' => [['day' => 1, 'title' => 'x']], 'inclusions' => ['Hotel']], 1);
        $departure = $packages->addDeparture($package, now()->addDays(30)->toDateString(), now()->addDays(34)->toDateString(), 10, ['double' => '900']);
        $packages->attachContract($package, UploadedFile::fake()->create('c.pdf', 5, 'application/pdf'));
        $packages->submit($package->refresh(), 1);
        $packages->approve($package->refresh(), 1);
        $bookings = app(TravelBookings::class);
        $contact = ['name' => 'Ali Hassan', 'email' => 'ali@example.com', 'phone' => '0507654321'];
        $booking = $bookings->request($departure->refresh(), null, [['room_type' => 'double', 'travellers' => 1]], $contact);
        $other = $bookings->request($departure->refresh(), null, [['room_type' => 'double', 'travellers' => 2]], $contact);

        $this->actingAs($otherUser)->post(onPanel('travel-agency', "bookings/{$booking->id}/confirm"))->assertNotFound();
        $this->actingAs($otherUser)->get(onPanel('travel-agency', "packages/{$package->id}"))->assertNotFound();
        $this->actingAs($user)->get(onPanel('travel-agency', 'bookings'))->assertOk()->assertSee($booking->number)->assertSee('+971507654321')->assertSee('ali@example.com');
        $this->actingAs($user)->post(onPanel('travel-agency', "bookings/{$booking->id}/confirm"))->assertRedirect();
        $this->actingAs($user)->post(onPanel('travel-agency', "bookings/{$other->id}/reject"), ['reason' => 'Fully booked hotel'])->assertRedirect();

        expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed)->and($booking->confirmation_number)->not->toBeNull()
            ->and($other->refresh()->status)->toBe(BookingStatus::Rejected)
            ->and($departure->refresh()->seats_reserved)->toBe(1);
        $outsider = User::factory()->create();
        $outsider->memberships()->create(['role' => MembershipRole::TravelAgency]);
        $this->actingAs($outsider)->get(onPanel('travel-agency', 'packages'))->assertForbidden();
    });
});

describe('Admin review queues', function () {
    it('approves ads and handles abuse reports, with the classifieds permission', function () {
        [$owner, $shop] = classifiedShop();
        $pending = shopAd($owner, $shop, live: false);
        $admin = adminAccount();

        $this->actingAs($admin)->get(onPanel('admin', 'classifieds'))->assertOk()->assertSee('L-shaped sofa grey');
        $this->actingAs($admin)->post(onPanel('admin', "classifieds/{$pending->id}/approve"))->assertRedirect();
        expect($pending->refresh()->status)->toBe(ClassifiedAdStatus::Active);

        app(ClassifiedReports::class)->report($pending, null, 'fraud', 'Fake');
        $report = ClassifiedReport::query()->sole();
        $this->actingAs($admin)->get(onPanel('admin', 'classifieds/reports'))->assertOk()->assertSee('fraud');
        $this->actingAs($admin)->post(onPanel('admin', "classifieds/reports/{$report->id}"), ['decision' => 'dismissed'])->assertRedirect();
        expect($report->refresh()->status->value)->toBe('dismissed');

        $support = adminAccount('uae', 'support');
        $this->actingAs($support)->get(onPanel('admin', 'classifieds'))->assertForbidden();
        $this->actingAs($support)->get(onPanel('admin', 'marketing'))->assertForbidden();
    });

    it('reviews sponsored campaigns and runs coupon participation from the admin', function () {
        $listing = sellableListing();
        $campaigns = app(AdCampaigns::class);
        $campaign = $campaigns->create($listing->vendor, ['listing_id' => $listing->id, 'bid' => '1', 'daily_budget' => '20'], 1);
        $campaigns->submit($campaign, 1);
        $coupon = Coupon::factory()->create(['code' => 'RAMADAN', 'scope' => 'platform', 'type' => 'percentage', 'value' => '5']);
        $marketing = adminAccount('uae', 'marketing');

        $this->actingAs($marketing)->get(onPanel('admin', 'marketing'))->assertOk()->assertSee($campaign->number);
        $this->actingAs($marketing)->post(onPanel('admin', "marketing/campaigns/{$campaign->id}"), ['decision' => 'reject'])->assertSessionHasErrors('reason');
        $this->actingAs($marketing)->post(onPanel('admin', "marketing/campaigns/{$campaign->id}"), ['decision' => 'approve'])->assertRedirect();
        expect($campaign->refresh()->status)->toBe(AdCampaignStatus::Active);

        $this->actingAs($marketing)->post(onPanel('admin', 'marketing/coupon-participation'), ['coupon_id' => $coupon->id, 'title' => 'Ramadan deals', 'min_fee' => '30', 'capacity' => 5, 'closes_at' => now()->addDays(3)->format('Y-m-d\TH:i'), 'open' => '1'])->assertRedirect();
        $invitation = CouponParticipationInvitation::query()->sole();
        $request = app(CouponParticipations::class)->request($invitation, 'vendor', $listing->vendor_id, '30', 'wallet', null, 1);
        $this->actingAs($marketing)->get(onPanel('admin', 'marketing/coupon-participation'))->assertOk()->assertSee('Ramadan deals');
        $this->actingAs($marketing)->post(onPanel('admin', "marketing/coupon-participation/requests/{$request->id}"), ['decision' => 'approve'])->assertRedirect();

        expect($invitation->status->value)->toBe('open')->and($request->refresh()->status)->toBe(ParticipationRequestStatus::Paid);
    });
});

describe('Seller order detail', function () {
    it('shows made-to-order measurements to the seller', function () {
        [$user, $vendor] = sellerAccount('uae');
        $subOrder = subOrderFor($vendor);
        $subOrder->items()->update(['measurements' => json_encode(['height' => 164, 'sleeve' => 22.5]), 'measurement_note' => 'Wide sleeves']);

        $this->actingAs($user)->get(onPanel('partner', "orders/{$subOrder->id}"))->assertOk()->assertSee('sleeve 22.5')->assertSee('Wide sleeves');
    });
});
