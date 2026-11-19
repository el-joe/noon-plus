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
use App\Shared\Notifications\Email\EventEmail;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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

    it('takes viewing requests from guests as contact details and opens a lead the broker moves on their own (D12)', function () {
        [, $broker] = marketerAccount(['broker']);
        $ad = liveProperty($broker);
        $contact = ['name' => 'Sara M.', 'email' => 'sara@example.com', 'phone' => '+971 55 765 4321', 'message' => 'After 6 pm please'];

        $this->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/viewings", $contact + ['preferred_at' => now()->subHour()->toDateTimeString()])->assertStatus(422);
        $this->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/viewings", ['name' => 'Sara M.', 'preferred_at' => now()->addDay()->toDateTimeString()])->assertUnprocessable()->assertJsonValidationErrors(['email', 'phone'], 'error.fields');
        $response = $this->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/viewings", $contact + ['preferred_at' => now()->addDays(2)->toDateTimeString()])->assertCreated()
            ->assertJsonPath('data.type', 'viewing')->assertJsonMissingPath('data.status');
        $viewing = PropertyViewing::query()->findOrFail($response->json('data.id'));

        expect($viewing)->customer_user_id->toBeNull()->contact_phone->toBe('+971557654321')->note->toBe('After 6 pm please')
            ->and($viewing->toArray())->not->toHaveKeys(['status', 'scheduled_at'])
            ->and(BrokerLead::query()->sole())->name->toBe('Sara M.')->phone->toBe('+971557654321')->notes->toBe('After 6 pm please')->status->toBe(LeadStatus::New);
        Notification::assertSentTo(User::query()->find($broker->user_id), EventEmail::class, fn (EventEmail $mail): bool => $mail->event === 'viewing.requested');
        Notification::assertSentOnDemandTimes(EventEmail::class, 0);
        expect(method_exists(Viewings::class, 'confirm'))->toBeFalse()
            ->and(method_exists(Viewings::class, 'move'))->toBeFalse()
            ->and(file_exists(app_path('Domains/RealEstate/Enums/ViewingStatus.php')))->toBeFalse();

        $leads = app(BrokerLeads::class);
        $leads->move(BrokerLead::query()->sole(), LeadStatus::ViewingScheduled, 1);
        expect(BrokerLead::query()->sole()->status)->toBe(LeadStatus::ViewingScheduled)
            ->and(DB::connection($viewing->getConnectionName())->table('state_transitions')->where('subject_type', 'property_viewing')->exists())->toBeFalse();
    });

    it('has no confirm, reject, schedule or complete endpoint for a request (D12)', function () {
        $ad = liveProperty();
        [, $token] = customerWithToken();
        $id = $this->withToken($token)->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/viewings", ['name' => 'Sara M.', 'email' => 'sara@example.com', 'phone' => '0501234567', 'preferred_at' => now()->addDay()->toDateTimeString()])
            ->assertCreated()->json('data.id');

        foreach (['confirm', 'reject', 'schedule', 'complete', 'cancel', 'status'] as $action) {
            $this->withToken($token)->postJson("/api/v1/uae-en/me/viewing-requests/{$id}/{$action}")->assertNotFound();
            $this->withToken($token)->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/viewings/{$id}/{$action}")->assertNotFound();
            $this->withToken($token)->postJson("/api/v1/uae-en/classifieds/mine/requests/{$id}/{$action}")->assertNotFound();
        }

        $this->withToken($token)->patchJson('/api/v1/uae-en/me/viewing-requests', ['status' => 'confirmed'])->assertStatus(405);
        $this->withToken($token)->getJson('/api/v1/uae-en/me/viewing-requests')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id)->assertJsonMissingPath('data.0.status')->assertJsonMissingPath('data.0.scheduled_at');
    });

    it('lists the requests on the owner\'s own ads with the contact details, and nobody else\'s (D12)', function () {
        $owner = User::factory()->create();
        $mine = liveProperty();
        $mine->forceFill(['owner_user_id' => $owner->id])->save();
        $theirs = liveProperty();
        $contact = ['name' => 'Sara M.', 'email' => 'sara@example.com', 'phone' => '0501234567', 'preferred_at' => now()->addDay()->toDateTimeString(), 'message' => 'Evening please'];
        $this->postJson("/api/v1/uae-en/classifieds/ads/{$mine->number}/viewings", $contact + ['type' => 'reserve'])->assertCreated();
        $this->postJson("/api/v1/uae-en/classifieds/ads/{$theirs->number}/viewings", $contact)->assertCreated();
        $token = $owner->createToken('test', ['customer'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/uae-en/classifieds/mine/requests')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'reserve')
            ->assertJsonPath('data.0.ad.number', $mine->number)
            ->assertJsonPath('data.0.note', 'Evening please')
            ->assertJsonPath('data.0.contact', ['name' => 'Sara M.', 'email' => 'sara@example.com', 'phone' => '+971501234567'])
            ->assertJsonMissingPath('data.0.status');
        $this->withToken($token)->getJson('/api/v1/uae-en/classifieds/mine/requests?type=viewing')->assertOk()->assertJsonCount(0, 'data');
        app('auth')->forgetGuards();
        $this->withoutToken()->getJson('/api/v1/uae-en/classifieds/mine/requests')->assertUnauthorized();
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

it('QC-009: an admin grants an exclusive contract on an ad from the admin panel and revokes it', function () {
    $this->withoutVite();
    $ad = liveProperty();
    [, $broker] = marketerAccount(['broker'], attributes: ['display_name' => 'Gulf Broker']);
    $admin = adminAccount('uae', 'marketing');
    $window = ['starts_at' => now('Asia/Dubai')->subHour()->format('Y-m-d\TH:i'), 'ends_at' => now('Asia/Dubai')->addDays(30)->format('Y-m-d\TH:i')];

    $this->actingAs($admin)->get(onPanel('admin', 'real-estate'))->assertOk()->assertSee('Gulf Broker');
    $this->post(onPanel('admin', 'real-estate/exclusive-contracts'), ['marketer_profile_id' => $broker->id, 'subject_type' => 'ad', 'ad_number' => 'NOPE'] + $window)
        ->assertSessionHasErrors('ad_number');
    $this->post(onPanel('admin', 'real-estate/exclusive-contracts'), ['marketer_profile_id' => $broker->id, 'subject_type' => 'ad', 'ad_number' => $ad->number, 'file' => UploadedFile::fake()->create('deal.pdf', 20, 'application/pdf')] + $window)
        ->assertSessionHasNoErrors();

    $contract = ExclusiveContract::query()->sole();
    expect($contract->status)->toBe(ExclusiveStatus::Active)->and($contract->granted_by_user_id)->toBe($admin->id);
    $this->getJson("/api/v1/uae-en/classifieds/ads/{$ad->number}")->assertJsonPath('data.exclusive_marketer.name', 'Gulf Broker');
    $this->get(onPanel('admin', "real-estate/exclusive-contracts/{$contract->id}/file"))->assertOk();

    $this->actingAs(adminAccount('uae', 'support'))->post(onPanel('admin', "real-estate/exclusive-contracts/{$contract->id}/revoke"), ['reason' => 'Ended early'])->assertForbidden();
    $this->actingAs($admin)->post(onPanel('admin', "real-estate/exclusive-contracts/{$contract->id}/revoke"), ['reason' => 'Ended early'])->assertSessionHasNoErrors();
    expect($contract->refresh()->status)->toBe(ExclusiveStatus::Revoked);
});
