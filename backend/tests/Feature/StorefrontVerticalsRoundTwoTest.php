<?php

use App\Domains\Classifieds\Enums\ClassifiedAdStatus;
use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Classifieds\Models\ClassifiedCategory;
use App\Domains\Classifieds\Support\ClassifiedAdMachine;
use App\Domains\Classifieds\Support\ClassifiedAds;
use App\Domains\Onboarding\Models\AdvertiseInquiry;
use App\Domains\Onboarding\Models\PartnerApplication;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\RealEstate\Models\PropertyViewing;
use App\Domains\Settings\SettingsService;
use App\Domains\Travel\Models\TravelBooking;
use App\Domains\Travel\Models\TravelDeparture;
use App\Domains\Travel\Support\TravelBookings;
use App\Domains\Travel\Support\TravelPackages;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use App\Shared\Notifications\Email\EventEmail;
use App\Shared\Notifications\RequestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->country = $this->actAsCountry('uae');
    config(['nawy.payments.storefront_url' => 'https://shop.test']);
    Storage::fake('public');
    Storage::fake('local');
    Notification::fake();
    $this->admin = User::factory()->create();
});

/**
 * An approved trip with one departure in 30 days (round 2 tests).
 */
function roundTwoDeparture(): TravelDeparture
{
    $packages = app(TravelPackages::class);
    $package = $packages->create(Vendor::factory()->create(['type' => 'travel', 'store_name' => 'Gulf Holidays']), [
        'title_en' => 'Umrah 7 nights', 'title_ar' => 'عمرة', 'destination_city' => 'Makkah', 'nights' => 7, 'trip_type' => 'umrah',
        'itinerary' => [['day' => 1, 'title' => 'Arrival']], 'inclusions' => ['Hotel'],
    ], 1);
    $package->forceFill(['cover_path' => 'travel/umrah.jpg'])->save();
    $departure = $packages->addDeparture($package, now()->addDays(30)->toDateString(), now()->addDays(37)->toDateString(), 6, ['double' => '2000.5', 'single' => '2600']);
    $packages->attachContract($package, UploadedFile::fake()->create('contract.pdf', 50, 'application/pdf'));
    $packages->submit($package->refresh(), 1);
    $packages->approve($package->refresh(), 1);

    return $departure->refresh();
}

/**
 * A live classified ad in a category of the given vertical (round 2 tests).
 */
function roundTwoAd(string $vertical = 'general', ?User $owner = null): ClassifiedAd
{
    $root = ClassifiedCategory::query()->firstOrCreate(['slug' => $vertical.'-root'], ['name_en' => ucfirst($vertical), 'name_ar' => 'قسم', 'vertical' => $vertical]);
    $child = ClassifiedCategory::query()->firstOrCreate(['slug' => $vertical.'-child'], ['parent_id' => $root->id, 'name_en' => 'Child', 'name_ar' => 'فرعي']);
    $owner ??= User::factory()->create();
    $ad = app(ClassifiedAds::class)->create($owner->id, ['category_id' => $child->id, 'title' => 'iPhone 15', 'price' => '2500.5', 'city' => 'Dubai'] + ($vertical === 'property' ? [
        'property' => ['purpose' => 'rent', 'property_type' => 'apartment', 'bedrooms' => 2, 'bathrooms' => 3, 'area_sqm' => 110, 'permit_number' => 'RERA-1', 'permit_authority' => 'DLD'],
    ] : []));
    app(ClassifiedAds::class)->addPhoto($ad, UploadedFile::fake()->image('a.jpg'));
    app(ClassifiedAdMachine::class)->submit($ad->refresh(), ActorType::Customer, $owner->id);

    return app(ClassifiedAdMachine::class)->approve($ad->refresh(), 1)->refresh();
}

describe('travel (G-W18, G-W19, G-W20)', function () {
    it('shows the cover image and money rounded to the currency', function () {
        $departure = roundTwoDeparture();
        $slug = $departure->package->slug;

        $this->getJson('/api/v1/uae-en/travel/packages')->assertOk()
            ->assertJsonPath('data.0.from_price', ['amount' => '2000.50', 'currency' => 'AED'])
            ->assertJsonPath('data.0.cover_url', fn (?string $url): bool => str_ends_with((string) $url, '/storage/travel/umrah.jpg'));
        $this->getJson("/api/v1/uae-en/travel/packages/{$slug}")->assertOk()
            ->assertJsonPath('data.departures.0.rooms.0.price_per_person', ['amount' => '2000.50', 'currency' => 'AED']);
    });

    it('returns the guest token as a header and mails a status link with it', function () {
        $departure = roundTwoDeparture();

        $response = $this->postJson("/api/v1/uae-en/travel/departures/{$departure->id}/bookings", [
            'name' => 'Sara Ahmed', 'email' => 'Sara@Example.com', 'phone' => '0501234567', 'rooms' => [['room_type' => 'double', 'travellers' => 2]],
        ])->assertCreated()->assertHeader('X-Booking-Token')->assertJsonPath('data.quoted_total', ['amount' => '4001.00', 'currency' => 'AED']);
        $token = $response->headers->get('X-Booking-Token');
        $number = $response->json('data.number');
        expect($response->json('data.access_token'))->toBe($token);

        $link = "https://shop.test/uae-en/travel/bookings/{$number}?token={$token}";
        Notification::assertSentOnDemand(EventEmail::class, fn (EventEmail $mail, array $channels, AnonymousNotifiable $to): bool => $mail->event === 'travel.booking_received' && $mail->options['url'] === $link && array_key_first((array) $to->routes['mail']) === 'sara@example.com');
        $this->getJson("/api/v1/uae-en/travel/bookings/{$number}", ['X-Booking-Token' => $token])->assertOk()->assertJsonPath('data.number', $number);
    });

    it('resends booking links without saying whether a booking exists', function () {
        $departure = roundTwoDeparture();
        $booking = app(TravelBookings::class)->request($departure, null, [['room_type' => 'double', 'travellers' => 1]], ['name' => 'Sara', 'email' => 'sara@example.com', 'phone' => '0501234567']);
        Notification::fake();

        $this->postJson('/api/v1/uae-en/travel/bookings/resend-link', ['email' => 'nobody@example.com'])->assertStatus(202)->assertJsonPath('data.sent', true);
        Notification::assertNothingSent();

        $this->postJson('/api/v1/uae-en/travel/bookings/resend-link', ['email' => 'SARA@example.com'])->assertStatus(202)->assertJsonPath('data.sent', true);
        Notification::assertSentOnDemand(RequestNotification::class, fn (RequestNotification $mail): bool => $mail->kind === 'travel_booking.links'
            && $mail->actionUrl === "https://shop.test/uae-en/travel/bookings/{$booking->number}?token={$booking->access_token}");
        Cache::flush();
        $this->postJson('/api/v1/uae-en/travel/bookings/resend-link', ['email' => 'not-an-email'])->assertUnprocessable();

        $this->actAsCountry('ksa');
        Notification::fake();
        $this->postJson('/api/v1/ksa-en/travel/bookings/resend-link', ['email' => 'sara@example.com'])->assertStatus(202);
        Notification::assertNothingSent();
        expect(TravelBooking::query()->count())->toBe(0);
    });
});

describe('classifieds (G-W19, G-W21, G-W22, G-W24)', function () {
    it('rounds ad prices, filters by inherited vertical and shows property facts on cards', function () {
        $general = roundTwoAd('general');
        $property = roundTwoAd('property');

        $this->getJson('/api/v1/uae-en/classifieds/ads?vertical=property')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', $property->number)
            ->assertJsonPath('data.0.price', ['amount' => '2500.50', 'currency' => 'AED'])
            ->assertJsonPath('data.0.property.bedrooms', 2)
            ->assertJsonPath('data.0.property.bathrooms', 3);
        $this->getJson('/api/v1/uae-en/classifieds/ads?vertical=general')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', $general->number);
        $this->getJson('/api/v1/uae-en/classifieds/ads?bathrooms_min=4')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/uae-en/classifieds/ads?min_area=100&max_area=120')->assertOk()->assertJsonCount(1, 'data');

        $tree = $this->getJson('/api/v1/uae-en/classifieds/categories')->assertOk()->json('data');
        $propertyRoot = collect($tree)->firstWhere('slug', 'property-root');
        expect($propertyRoot['children'][0])->toMatchArray(['slug' => 'property-child', 'vertical' => 'property', 'own_vertical' => 'general', 'request_types' => ['viewing', 'reserve']]);

        $this->getJson("/api/v1/uae-en/classifieds/ads/{$general->number}")->assertOk()
            ->assertJsonPath('data.vertical', 'general')
            ->assertJsonPath('data.request_types', ['viewing', 'reserve'])
            ->assertJsonPath('data.seller.member_since', fn (?string $date): bool => $date !== null);
    });

    it('takes D8 viewing and reserve requests on any vertical the settings allow, and lists the customer\'s own', function () {
        $ad = roundTwoAd('general');
        [$customer, $token] = customerWithToken();
        $contact = ['name' => 'Sara M.', 'email' => 'sara@example.com', 'phone' => '0501234567', 'preferred_at' => now()->addDay()->toDateTimeString()];

        $this->withToken($token)->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/viewings", $contact + ['type' => 'reserve'])->assertCreated()->assertJsonPath('data.type', 'reserve');
        $this->withToken($token)->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/viewings", $contact)->assertCreated()->assertJsonPath('data.type', 'viewing');
        $this->withToken($token)->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/viewings", $contact + ['type' => 'buy'])->assertUnprocessable();
        // D12: a record only — the advertiser is told a request arrived; the customer gets no status mail.
        Notification::assertSentOnDemandTimes(EventEmail::class, 0);

        app(SettingsService::class)->set('classified.request_types', ['general' => ['viewing'], 'property' => ['viewing', 'reserve']], $this->country, $this->admin->id);
        $this->withToken($token)->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/viewings", $contact + ['type' => 'reserve'])->assertUnprocessable();
        app(SettingsService::class)->set('classified.request_types', ['general' => [], 'property' => ['viewing']], $this->country, $this->admin->id);
        $this->withToken($token)->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/viewings", $contact)->assertUnprocessable();

        PropertyViewing::query()->forceCreate(['classified_ad_id' => $ad->id, 'type' => 'viewing', 'customer_user_id' => 999, 'contact_name' => 'X', 'contact_email' => 'x@example.com', 'contact_phone' => '+971501234567', 'owner_user_id' => $ad->owner_user_id, 'preferred_at' => now()->addDay(), 'status' => 'requested']);
        $this->withToken($token)->getJson('/api/v1/uae-en/me/viewing-requests')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', 'viewing')
            ->assertJsonPath('data.1.type', 'reserve')
            ->assertJsonPath('data.0.ad.number', $ad->number)
            ->assertJsonMissingPath('data.0.status')
            ->assertJsonMissingPath('data.0.scheduled_at');
        $this->withToken($token)->getJson('/api/v1/uae-en/me/viewing-requests?type=reserve')->assertOk()->assertJsonCount(1, 'data');

        app(ClassifiedAdMachine::class)->pause($ad, ActorType::Customer, $ad->owner_user_id);
        app(SettingsService::class)->set('classified.request_types', ['general' => ['viewing']], $this->country, $this->admin->id);
        $this->withToken($token)->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/viewings", $contact)->assertUnprocessable();
    });

    it('reads whether the customer follows a shop', function () {
        $shop = Vendor::factory()->create(['type' => 'classified', 'slug' => 'gadget-hub']);
        [, $token] = customerWithToken();

        $this->withToken($token)->getJson('/api/v1/uae-en/follows?type=shop&slug=gadget-hub')->assertOk()->assertJsonPath('data', ['following' => false, 'followers' => 0]);
        $this->withToken($token)->postJson('/api/v1/uae-en/follows', ['type' => 'shop', 'slug' => 'gadget-hub'])->assertOk();
        $this->withToken($token)->getJson('/api/v1/uae-en/follows?type=shop&slug=gadget-hub')->assertOk()->assertJsonPath('data', ['following' => true, 'followers' => 1]);
        $this->withToken($token)->getJson('/api/v1/uae-en/follows?type=shop&slug=missing')->assertNotFound();
        expect($shop->exists)->toBeTrue();
    });

    it('copies a rejected ad into a new draft instead of editing it', function () {
        [$owner, $token] = customerWithToken();
        $root = ClassifiedCategory::query()->create(['slug' => 'phones', 'name_en' => 'Phones', 'name_ar' => 'هواتف']);
        $ad = app(ClassifiedAds::class)->create($owner->id, ['category_id' => $root->id, 'title' => 'Pixel 9', 'price' => '1800']);
        app(ClassifiedAds::class)->addPhoto($ad, UploadedFile::fake()->image('p.jpg'));
        app(ClassifiedAdMachine::class)->submit($ad->refresh(), ActorType::Customer, $owner->id);
        app(ClassifiedAdMachine::class)->reject($ad->refresh(), 1, 'Blurry photo');

        $this->withToken($token)->putJson("/api/v1/uae-en/classifieds/ads/{$ad->number}", ['title' => 'Pixel 9 Pro'])->assertUnprocessable();
        $copy = $this->withToken($token)->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/duplicate")->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.title', 'Pixel 9')
            ->assertJsonPath('data.image_count', 1)
            ->json('data.number');
        expect($copy)->not->toBe($ad->number)->and($ad->refresh()->status)->toBe(ClassifiedAdStatus::Rejected);
        $this->withToken($token)->putJson("/api/v1/uae-en/classifieds/ads/{$copy}", ['title' => 'Pixel 9 Pro'])->assertOk()->assertJsonPath('data.title', 'Pixel 9 Pro');
        $this->withToken($token)->postJson("/api/v1/uae-en/classifieds/ads/{$copy}/duplicate")->assertUnprocessable();
        $this->withToken($token)->getJson("/api/v1/uae-en/classifieds/ads/{$copy}/contract")->assertOk()->assertJsonPath('data.contract', null);

        app('auth')->forgetGuards();
        [, $stranger] = customerWithToken();
        $this->withToken($stranger)->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/duplicate")->assertNotFound();
    });
});

describe('partner applications (G-W28)', function () {
    it('returns the token as a header and mails the website resume link', function () {
        $response = $this->postJson('/api/v1/uae-ar/partner-applications', ['type' => 'travel_agency', 'name' => 'Sara', 'email' => 'sara@example.com', 'phone' => '+971501234567', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1'])
            ->assertCreated()->assertHeader('X-Application-Token');
        $number = $response->json('data.number');
        expect($response->headers->get('X-Application-Token'))->toBe($response->json('meta.access_token'));

        $this->postJson('/api/v1/uae-en/partner-applications/resume-link', ['email' => 'sara@example.com', 'type' => 'travel_agency'])->assertStatus(202);
        Notification::assertSentOnDemand(RequestNotification::class, fn (RequestNotification $mail): bool => $mail->kind === 'partner_application.resume'
            && str_starts_with((string) $mail->actionUrl, "https://shop.test/uae-ar/join/resume?number={$number}&token=")
            && PartnerApplication::query()->where('number', $number)->sole()->matchesToken(explode('token=', (string) $mail->actionUrl)[1]));
    });

    it('marks the documents step complete when it is saved with nothing missing', function () {
        $token = $this->postJson('/api/v1/uae-en/partner-applications', ['type' => 'marketer', 'name' => 'Sara', 'email' => 'sara@example.com', 'phone' => '+971501234567', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1'])
            ->assertCreated()->headers->get('X-Application-Token');
        $number = PartnerApplication::query()->value('number');

        $this->postJson("/api/v1/uae-en/partner-applications/{$number}/steps/documents", [], ['X-Application-Token' => $token])->assertOk()
            ->assertJsonPath('data.steps.2', ['key' => 'documents', 'completed' => true]);
    });

    it('splits the marketer join section into influencer, affiliate and broker and offers budget bands', function () {
        $marketer = collect($this->getJson('/api/v1/uae-en/join-options')->assertOk()->json('data'))->firstWhere('section', 'marketer');
        expect(array_column($marketer['roles'], 'role'))->toBe(['influencer', 'affiliate', 'broker'])
            ->and($marketer['roles'][0])->toMatchArray(['type' => 'marketer', 'job_types' => ['influencer']])
            ->and($marketer['roles'][2]['type'])->toBe('broker');

        $this->getJson('/api/v1/uae-en/advertise-requests/options')->assertOk()
            ->assertJsonPath('data.budget_bands.1', ['key' => '1000_5000', 'label' => 'AED 1,000 – 5,000', 'min' => 1000, 'max' => 5000, 'currency' => 'AED']);
        $base = ['name' => 'Sara', 'email' => 'sara@bloom.ae', 'phone' => '0501234567', 'advertiser_type' => 'brand', 'interest' => 'both', 'consent' => true];
        $this->postJson('/api/v1/uae-en/advertise-requests', $base + ['monthly_budget_band' => 'lots'])->assertUnprocessable();
        $this->postJson('/api/v1/uae-en/advertise-requests', $base + ['monthly_budget_band' => '5000_20000'])->assertCreated();
        expect(AdvertiseInquiry::query()->value('monthly_budget'))->toBe('5000_20000');
    });
});
