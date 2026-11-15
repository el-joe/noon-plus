<?php

use App\Domains\Classifieds\Enums\ClassifiedAdStatus;
use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Classifieds\Models\ClassifiedCategory;
use App\Domains\Classifieds\Support\ClassifiedAdMachine;
use App\Domains\Classifieds\Support\ClassifiedAds;
use App\Domains\Contracts\Enums\ContractKind;
use App\Domains\Contracts\Enums\SignatureMethod;
use App\Domains\Contracts\Enums\SignerType;
use App\Domains\Contracts\Models\ContractTemplate;
use App\Domains\Contracts\Support\ContractPublisher;
use App\Domains\Contracts\Support\ContractSigner;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\RealEstate\Enums\ExclusiveStatus;
use App\Domains\RealEstate\Enums\LeadStatus;
use App\Domains\RealEstate\Enums\SpecialRequestStatus;
use App\Domains\RealEstate\Models\BrokerLead;
use App\Domains\RealEstate\Models\ExclusiveContract;
use App\Domains\RealEstate\Models\PropertyViewing;
use App\Domains\RealEstate\Models\SpecialRequest;
use App\Domains\RealEstate\Support\BrokerLeads;
use App\Domains\RealEstate\Support\ExclusiveContracts;
use App\Domains\RealEstate\Support\SpecialRequests;
use App\Domains\RealEstate\Support\Viewings;
use App\Models\User;
use App\Shared\Notifications\RequestNotification;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->country = $this->actAsCountry('uae');
    Storage::fake('public');
    Storage::fake('local');
    Notification::fake();
    $this->property = ClassifiedCategory::query()->create(['slug' => 'property', 'name_en' => 'Property', 'name_ar' => 'عقارات', 'vertical' => 'property']);
    $this->apartments = ClassifiedCategory::query()->create(['parent_id' => $this->property->id, 'slug' => 'apartments-rent', 'name_en' => 'Apartments for rent', 'name_ar' => 'شقق للإيجار']);
});

/**
 * A live property ad owned by a broker (or an individual).
 *
 * @param  array<string, mixed>  $property
 */
function liveProperty(?MarketerProfile $broker = null, array $property = []): ClassifiedAd
{
    $owner = $broker === null ? User::factory()->create() : User::query()->find($broker->user_id);
    $ad = app(ClassifiedAds::class)->create($owner->id, [
        'category_id' => test()->apartments->id, 'title' => '2BR Marina Gate', 'price' => '140000', 'city' => 'Dubai',
        'property' => $property + ['purpose' => 'rent', 'property_type' => 'apartment', 'bedrooms' => 2, 'bathrooms' => 2, 'area_sqm' => 110, 'rent_period' => 'yearly', 'permit_number' => 'RERA-71234', 'permit_authority' => 'DLD'],
    ], ActorType::Marketer, null, $broker);
    app(ClassifiedAds::class)->addPhoto($ad, UploadedFile::fake()->image('flat.jpg'));
    app(ClassifiedAdMachine::class)->submit($ad->refresh(), ActorType::Marketer, $owner->id);

    return app(ClassifiedAdMachine::class)->approve($ad->refresh(), 1)->refresh();
}

describe('properties', function () {
    it('needs an advertising permit before review and shows the property details', function () {
        $ad = app(ClassifiedAds::class)->create(User::factory()->create()->id, [
            'category_id' => $this->apartments->id, 'title' => 'Studio JVC for rent', 'price' => '45000',
            'property' => ['purpose' => 'rent', 'property_type' => 'studio'],
        ]);
        app(ClassifiedAds::class)->addPhoto($ad, UploadedFile::fake()->image('s.jpg'));

        expect(fn () => app(ClassifiedAdMachine::class)->submit($ad->refresh(), ActorType::Customer, 1))->toThrow(TransitionRefused::class, 'permit number');
        $ad->property->update(['permit_number' => 'RERA-1', 'permit_expires_on' => now()->subDay()->toDateString()]);
        expect(fn () => app(ClassifiedAdMachine::class)->submit($ad->refresh(), ActorType::Customer, 1))->toThrow(TransitionRefused::class, 'expired');

        $live = liveProperty();
        $this->getJson("/api/v1/uae-en/classifieds/ads/{$live->number}")->assertOk()
            ->assertJsonPath('data.property.permit_number', 'RERA-71234')->assertJsonPath('data.property.bedrooms', 2);
        expect(array_column($this->getJson('/api/v1/uae-en/classifieds/ads?purpose=rent&bedrooms_min=2')->json('data'), 'number'))->toBe([$live->number])
            ->and($this->getJson('/api/v1/uae-en/classifieds/ads?purpose=sale')->json('data'))->toBe([]);
    });

    it('lets only an active broker who signed the broker agreement publish as a broker', function () {
        [, $pending] = marketerAccount(['broker'], 'pending');
        expect(fn () => liveProperty($pending))->toThrow(TransitionRefused::class, 'active broker');

        [, $broker] = marketerAccount(['broker']);
        $template = ContractTemplate::query()->create(['country_id' => $this->country->id, 'kind' => ContractKind::Broker, 'name' => 'Broker agreement']);
        $version = app(ContractPublisher::class)->publish(app(ContractPublisher::class)->draft($template, ['body_ar' => 'اتفاقية', 'body_en' => 'Broker agreement']), 1);
        expect(fn () => liveProperty($broker))->toThrow(TransitionRefused::class, 'broker agreement');

        app(ContractSigner::class)->sign($version, SignerType::Marketer, $broker->id, $broker->user_id, 'Hessa M', SignatureMethod::Click);
        expect(liveProperty($broker)->status)->toBe(ClassifiedAdStatus::Active);
    });
});

describe('broker leads', function () {
    it('opens one lead per inquiry on a broker ad and moves it through the pipeline with follow-ups', function () {
        [, $broker] = marketerAccount(['broker']);
        $ad = liveProperty($broker);
        [$buyer, $token] = customerWithToken();

        $this->withToken($token)->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/inquiries", ['message' => 'Is it available in March?', 'phone' => '0501234567'])->assertCreated();
        $this->withToken($token)->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/inquiries", ['message' => 'And parking?', 'phone' => '0501234567'])->assertCreated();

        $lead = BrokerLead::query()->sole();
        expect($lead)->marketer_profile_id->toBe($broker->id)->source->toBe('inquiry')->name->toBe($buyer->name)->status->toBe(LeadStatus::New);

        $leads = app(BrokerLeads::class);
        $leads->move($lead, LeadStatus::Contacted, 1);
        $call = $leads->addFollowUp($lead->refresh(), 'call', now()->addDay(), 'Call about parking');
        $leads->addFollowUp($lead->refresh(), 'whatsapp', now()->addDays(3));
        expect($lead->refresh()->next_follow_up_at->toDateString())->toBe(now()->addDay()->toDateString());
        $leads->completeFollowUp($call);
        expect($lead->refresh()->next_follow_up_at->toDateString())->toBe(now()->addDays(3)->toDateString());

        expect(fn () => $leads->move($lead->refresh(), LeadStatus::Won, 1))->toThrow(TransitionRefused::class);
        expect(fn () => $leads->move($lead->refresh(), LeadStatus::Lost, 1))->toThrow(TransitionRefused::class, 'why');
        $leads->move($lead->refresh(), LeadStatus::Negotiating, 1);
        $leads->move($lead->refresh(), LeadStatus::Won, 1);
        expect($lead->refresh())->status->toBe(LeadStatus::Won)->next_follow_up_at->toBeNull();
    });

    it('takes viewing requests from guests as contact details, opens a lead and moves it on confirmation', function () {
        [, $broker] = marketerAccount(['broker']);
        $ad = liveProperty($broker);
        $contact = ['name' => 'Sara M.', 'email' => 'sara@example.com', 'phone' => '+971 55 765 4321', 'message' => 'After 6 pm please'];

        $this->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/viewings", $contact + ['preferred_at' => now()->subHour()->toDateTimeString()])->assertStatus(422);
        $this->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/viewings", ['name' => 'Sara M.', 'preferred_at' => now()->addDay()->toDateTimeString()])->assertUnprocessable()->assertJsonValidationErrors(['email', 'phone'], 'error.fields');
        $viewingId = $this->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/viewings", $contact + ['preferred_at' => now()->addDays(2)->toDateTimeString()])->assertCreated()->json('data.id');
        $viewing = PropertyViewing::query()->findOrFail($viewingId);

        expect($viewing)->customer_user_id->toBeNull()->contact_phone->toBe('+971557654321')
            ->and(BrokerLead::query()->sole())->name->toBe('Sara M.')->phone->toBe('+971557654321')->notes->toBe('After 6 pm please');
        Notification::assertSentTo(User::query()->find($broker->user_id), RequestNotification::class, fn (RequestNotification $mail): bool => $mail->kind === 'viewing.requested');
        Notification::assertSentOnDemand(RequestNotification::class, fn (RequestNotification $mail, array $channels, object $notifiable): bool => $mail->kind === 'viewing.received' && $notifiable->routes['mail'] === 'sara@example.com');

        app(Viewings::class)->confirm($viewing, 1);
        expect(BrokerLead::query()->sole()->status)->toBe(LeadStatus::ViewingScheduled);
    });
});

describe('special requests', function () {
    it('lets exactly one broker claim an open request and gives the winner a lead', function () {
        [, $token] = customerWithToken();
        $number = $this->withToken($token)->postJson('/api/v1/uae-en/special-requests', ['purpose' => 'sale', 'property_type' => 'villa', 'city' => 'Dubai', 'bedrooms' => 3, 'budget_max' => '4200000', 'details' => 'Looking for a 3-bedroom villa in Arabian Ranches'])
            ->assertCreated()->assertJsonPath('data.status', 'open')->json('data.number');
        $request = SpecialRequest::query()->where('number', $number)->sole();
        [, $first] = marketerAccount(['broker']);
        [, $second] = marketerAccount(['broker']);
        [, $affiliate] = marketerAccount(['affiliate']);
        $requests = app(SpecialRequests::class);

        expect(fn () => $requests->claim($request, $affiliate, 1))->toThrow(TransitionRefused::class, 'active broker');
        $stale = SpecialRequest::query()->findOrFail($request->id);
        $requests->claim($request, $first, 1);
        expect(fn () => $requests->claim($stale, $second, 1))->toThrow(TransitionRefused::class, 'Another broker');

        expect($request->refresh())->status->toBe(SpecialRequestStatus::InProgress)->claimed_by_marketer_profile_id->toBe($first->id)
            ->and(BrokerLead::query()->sole())->marketer_profile_id->toBe($first->id)->source->toBe('special_request');
        expect(fn () => $requests->finish($request, $second, SpecialRequestStatus::Fulfilled, 1))->toThrow(TransitionRefused::class);
        $requests->finish($request, $first, SpecialRequestStatus::Fulfilled, 1);
        $this->withToken($token)->postJson("/api/v1/uae-en/special-requests/{$number}/cancel")->assertStatus(422);
    });
});

describe('exclusive contracts', function () {
    it('shows the exclusive marketer, the ad grant beating the category grant, and expires daily', function () {
        $ad = liveProperty();
        [, $categoryBroker] = marketerAccount(['broker'], attributes: ['display_name' => 'Category Broker']);
        [, $adBroker] = marketerAccount(['broker'], attributes: ['display_name' => 'Ad Broker']);
        $contracts = app(ExclusiveContracts::class);

        $contracts->grant($categoryBroker, ExclusiveContract::SUBJECT_CATEGORY, $this->property->id, now()->subDay(), now()->addDays(10), 1, UploadedFile::fake()->create('excl.pdf', 10, 'application/pdf'));
        $this->getJson("/api/v1/uae-en/classifieds/ads/{$ad->number}")->assertJsonPath('data.exclusive_marketer.name', 'Category Broker');

        $grant = $contracts->grant($adBroker, ExclusiveContract::SUBJECT_AD, $ad->id, now()->addDay(), now()->addDays(5), 1);
        expect($grant->status)->toBe(ExclusiveStatus::Scheduled);
        expect(fn () => $contracts->grant($categoryBroker, ExclusiveContract::SUBJECT_AD, $ad->id, now()->addDays(2), now()->addDays(3), 1))->toThrow(TransitionRefused::class, 'covers these dates');

        $this->travel(2)->days();
        $this->artisan('nawy:exclusive-contracts:sync')->assertSuccessful();
        $this->getJson("/api/v1/uae-en/classifieds/ads/{$ad->number}")->assertJsonPath('data.exclusive_marketer.name', 'Ad Broker')->assertJsonPath('data.exclusive_marketer.scope', 'ad');

        $this->travel(10)->days();
        $this->artisan('nawy:exclusive-contracts:sync')->assertSuccessful();
        expect(ExclusiveContract::query()->pluck('status')->all())->toBe([ExclusiveStatus::Expired, ExclusiveStatus::Expired]);
        $this->getJson("/api/v1/uae-en/classifieds/ads/{$ad->number}")->assertJsonPath('data.exclusive_marketer', null);
    });
});
