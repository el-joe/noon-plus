<?php

use App\Domains\Classifieds\Enums\ClassifiedAdStatus;
use App\Domains\Classifieds\Enums\ReportStatus;
use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Classifieds\Models\ClassifiedCategory;
use App\Domains\Classifieds\Models\ClassifiedPackage;
use App\Domains\Classifieds\Models\ClassifiedReport;
use App\Domains\Classifieds\Models\ClassifiedThread;
use App\Domains\Classifieds\Models\PhoneReveal;
use App\Domains\Classifieds\Models\SavedSearch;
use App\Domains\Classifieds\Notifications\ClassifiedRenewalReminder;
use App\Domains\Classifieds\Notifications\SavedSearchMatches;
use App\Domains\Classifieds\Support\ClassifiedAdMachine;
use App\Domains\Classifieds\Support\ClassifiedAds;
use App\Domains\Classifieds\Support\ClassifiedReports;
use App\Domains\Contracts\Enums\ContractKind;
use App\Domains\Contracts\Models\ContractAssignment;
use App\Domains\Contracts\Models\ContractTemplate;
use App\Domains\Contracts\Models\ContractVersion;
use App\Domains\Contracts\Support\ContractPublisher;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Orders\Enums\ActorType;
use App\Models\User;
use App\Shared\Notifications\Email\EventEmail;
use App\Shared\StateMachine\StateTransition;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->country = $this->actAsCountry('uae');
    Storage::fake('public');
    Storage::fake('local');
    Notification::fake();

    $this->motors = ClassifiedCategory::query()->create(['slug' => 'motors', 'name_en' => 'Motors', 'name_ar' => 'سيارات', 'vertical' => 'motors']);
    $this->cars = ClassifiedCategory::query()->create(['parent_id' => $this->motors->id, 'slug' => 'cars', 'name_en' => 'Cars', 'name_ar' => 'سيارات للبيع']);
    $this->motors->ownAttributes()->create(['key' => 'year', 'label_en' => 'Year', 'label_ar' => 'السنة', 'type' => 'year', 'min_value' => 1950, 'max_value' => 2030, 'is_required' => true, 'is_filterable' => true]);
    $this->cars->ownAttributes()->create(['key' => 'make', 'label_en' => 'Make', 'label_ar' => 'الماركة', 'type' => 'select', 'options' => ['toyota', 'nissan', 'bmw'], 'is_required' => true, 'is_filterable' => true]);
    $this->cars->ownAttributes()->create(['key' => 'features', 'label_en' => 'Features', 'label_ar' => 'المزايا', 'type' => 'multiselect', 'options' => ['sunroof', 'leather', 'camera'], 'is_filterable' => true]);
});

/**
 * A template of `$kind` in the current country, published, assigned to a classified category.
 */
function classifiedContract(ContractKind $kind, ClassifiedCategory $category): ContractVersion
{
    $country = test()->country;
    $template = ContractTemplate::query()->create(['country_id' => $country->id, 'kind' => $kind, 'name' => $kind->label().' cars']);
    $version = app(ContractPublisher::class)->publish(app(ContractPublisher::class)->draft($template, ['body_ar' => 'عقد', 'body_en' => 'Contract']), 1);
    ContractAssignment::query()->create(['contract_template_id' => $template->id, 'country_id' => $country->id, 'subject_type' => ContractAssignment::SUBJECT_CLASSIFIED_CATEGORY, 'subject_key' => (string) $category->id]);

    return $version;
}

/**
 * A live ad (created through the service, approved by an admin).
 *
 * @param  array<string, mixed>  $data
 */
function liveAd(?User $owner = null, array $data = [], ?ClassifiedCategory $category = null): ClassifiedAd
{
    $owner ??= User::factory()->create();
    $category ??= test()->cars;
    $ad = app(ClassifiedAds::class)->create($owner->id, $data + [
        'category_id' => $category->id, 'title' => 'Toyota Land Cruiser GXR', 'price' => '189000', 'city' => 'Dubai',
        'specs' => ['year' => 2021, 'make' => 'toyota'], 'latitude' => 25.08, 'longitude' => 55.14, 'contact_phone' => '+971500000009',
    ]);
    app(ClassifiedAds::class)->addPhoto($ad, UploadedFile::fake()->image('car.jpg'));
    app(ClassifiedAdMachine::class)->submit($ad->refresh(), ActorType::Customer, $owner->id);

    return app(ClassifiedAdMachine::class)->approve($ad->refresh(), 1)->refresh();
}

describe('ad lifecycle', function () {
    it('posts through the wizard, is never public before approval and goes live for the category period', function () {
        [$owner, $token] = customerWithToken();
        [, $otherToken] = customerWithToken();

        $number = $this->withToken($token)->postJson('/api/v1/uae-en/classifieds/ads', [
            'category_id' => $this->cars->id, 'title' => 'Nissan Patrol 2020', 'price' => '120000', 'city' => 'Dubai',
            'specs' => ['year' => 2020, 'make' => 'nissan', 'features' => ['sunroof', 'camera'], 'unknown' => 'dropped'],
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonMissingPath('data.specs.unknown')->json('data.number');

        $this->withToken($token)->postJson("/api/v1/uae-en/classifieds/ads/{$number}/submit")->assertStatus(422)->assertJsonPath('error.message', 'Add at least one photo.');
        $this->withToken($token)->post("/api/v1/uae-en/classifieds/ads/{$number}/photos", ['photo' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertCreated();
        $this->withToken($token)->postJson("/api/v1/uae-en/classifieds/ads/{$number}/submit")->assertOk()->assertJsonPath('data.status', 'pending_review');

        app('auth')->forgetGuards();
        $this->withoutToken()->getJson("/api/v1/uae-en/classifieds/ads/{$number}")->assertNotFound();
        $this->withToken($otherToken)->getJson("/api/v1/uae-en/classifieds/ads/{$number}")->assertNotFound();
        app('auth')->forgetGuards();
        $this->withToken($otherToken)->postJson("/api/v1/uae-en/classifieds/ads/{$number}/pause")->assertNotFound();
        app('auth')->forgetGuards();
        expect($this->withoutToken()->getJson('/api/v1/uae-en/classifieds/ads')->json('meta.total'))->toBe(0);

        $ad = ClassifiedAd::query()->where('number', $number)->sole();
        app(ClassifiedAdMachine::class)->approve($ad, 1);

        expect($ad->refresh()->status)->toBe(ClassifiedAdStatus::Active)
            ->and((int) round($ad->approved_at->diffInDays($ad->expires_at)))->toBe(30)
            ->and(StateTransition::query()->where('subject_type', 'classified_ad')->where('subject_id', $ad->id)->pluck('to_status')->all())->toBe(['draft', 'pending_review', 'active']);
        $this->getJson("/api/v1/uae-en/classifieds/ads/{$number}")->assertOk()->assertJsonPath('data.specs.features', ['sunroof', 'camera'])->assertJsonMissingPath('data.contact_phone');
    });

    it('validates attribute types on drafts and required attributes on submission', function () {
        [, $token] = customerWithToken();

        $this->withToken($token)->postJson('/api/v1/uae-en/classifieds/ads', ['category_id' => $this->cars->id, 'title' => 'Old car here', 'price' => '5000', 'specs' => ['year' => 1900, 'make' => 'ferrari']])
            ->assertUnprocessable()->assertJsonValidationErrors(['specs.year', 'specs.make'], 'error.fields');

        $ad = app(ClassifiedAds::class)->create(User::factory()->create()->id, ['category_id' => $this->cars->id, 'title' => 'Car without make', 'price' => '5000', 'specs' => ['year' => 2015]]);
        app(ClassifiedAds::class)->addPhoto($ad, UploadedFile::fake()->image('x.jpg'));

        expect(fn () => app(ClassifiedAdMachine::class)->submit($ad->refresh(), ActorType::Customer, 1))->toThrow(ValidationException::class);
    });

    it('lets a wanted ad go to review without a price or photo, and records a rejection reason', function () {
        $ad = app(ClassifiedAds::class)->create(User::factory()->create()->id, ['category_id' => $this->cars->id, 'kind' => 'wanted', 'title' => 'Looking for a BMW X5', 'specs' => ['year' => 2019, 'make' => 'bmw']]);
        app(ClassifiedAdMachine::class)->submit($ad, ActorType::Customer, 1);

        expect(fn () => app(ClassifiedAdMachine::class)->reject($ad->refresh(), 1, 'no'))->toThrow(TransitionRefused::class);
        app(ClassifiedAdMachine::class)->reject($ad->refresh(), 1, 'Duplicate of another ad');

        expect($ad->refresh())->status->toBe(ClassifiedAdStatus::Rejected)->rejection_reason->toBe('Duplicate of another ad');
    });
});

describe('contract before submission (FR-CLS-1)', function () {
    it('holds the ad in pending_contract until the seller signs, then sends it to review', function () {
        $this->cars->update(['requires_contract' => true]);
        $version = classifiedContract(ContractKind::SellerCategory, $this->motors);
        [, $token] = customerWithToken();

        $number = $this->withToken($token)->postJson('/api/v1/uae-en/classifieds/ads', ['category_id' => $this->cars->id, 'title' => 'BMW M3 2018', 'price' => '90000', 'specs' => ['year' => 2018, 'make' => 'bmw']])->json('data.number');
        $this->withToken($token)->post("/api/v1/uae-en/classifieds/ads/{$number}/photos", ['photo' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json']);

        $this->withToken($token)->postJson("/api/v1/uae-en/classifieds/ads/{$number}/submit")->assertOk()
            ->assertJsonPath('data.status', 'pending_contract')->assertJsonPath('data.contract.version_id', $version->id);
        $this->withToken($token)->postJson("/api/v1/uae-en/classifieds/ads/{$number}/contract", ['full_name' => 'Omar Saleh'])->assertOk()->assertJsonPath('data.status', 'pending_review');

        $ad = ClassifiedAd::query()->where('number', $number)->sole();
        expect($ad->contract_signature_id)->not->toBeNull()
            ->and($ad->submitted_at)->not->toBeNull();

        $second = app(ClassifiedAds::class)->create($ad->owner_user_id, ['category_id' => $this->cars->id, 'title' => 'Second BMW here', 'price' => '80000', 'specs' => ['year' => 2017, 'make' => 'bmw']]);
        app(ClassifiedAds::class)->addPhoto($second, UploadedFile::fake()->image('b.jpg'));
        expect(app(ClassifiedAdMachine::class)->submit($second->refresh(), ActorType::Customer, 1)->status)->toBe(ClassifiedAdStatus::PendingReview);
    });

    it('refuses submission when the category requires a contract that is not configured', function () {
        $this->cars->update(['requires_contract' => true]);
        $ad = app(ClassifiedAds::class)->create(User::factory()->create()->id, ['category_id' => $this->cars->id, 'title' => 'Toyota Camry 2016', 'price' => '30000', 'specs' => ['year' => 2016, 'make' => 'toyota']]);
        app(ClassifiedAds::class)->addPhoto($ad, UploadedFile::fake()->image('c.jpg'));

        expect(fn () => app(ClassifiedAdMachine::class)->submit($ad->refresh(), ActorType::Customer, 1))
            ->toThrow(TransitionRefused::class, 'No contract is configured for this classified category');
    });
});

describe('search', function () {
    it('filters by attributes, price, kind and map bounds, featured first', function () {
        $land = liveAd(data: ['title' => 'Land Cruiser 2021', 'specs' => ['year' => 2021, 'make' => 'toyota', 'features' => ['sunroof']]]);
        $old = liveAd(data: ['title' => 'Old Patrol', 'price' => '20000', 'specs' => ['year' => 2008, 'make' => 'nissan'], 'latitude' => 24.45, 'longitude' => 54.37, 'city' => 'Abu Dhabi']);
        $bmw = liveAd(data: ['title' => 'BMW X5', 'price' => '150000', 'specs' => ['year' => 2019, 'make' => 'bmw', 'features' => ['sunroof', 'leather']]]);
        $bmw->forceFill(['featured_until' => now()->addDays(3)])->save();

        $numbers = fn (string $query): array => array_column($this->getJson('/api/v1/uae-en/classifieds/ads?'.$query)->assertOk()->json('data'), 'number');

        expect($numbers('category=motors'))->toBe([$bmw->number, $old->number, $land->number])
            ->and($numbers('category=cars&attr[make]=nissan'))->toBe([$old->number])
            ->and($numbers('attr[year][min]=2015&sort=price_asc'))->toBe([$bmw->number, $land->number])
            ->and($numbers('attr[features][]=leather'))->toBe([$bmw->number])
            ->and($numbers('attr[year]=2008'))->toBe([$old->number])
            ->and($numbers('max_price=100000'))->toBe([$old->number])
            ->and($numbers('bounds=24.0,54.0,24.9,54.9'))->toBe([$old->number])
            ->and($numbers('kind=wanted'))->toBe([]);

        $this->getJson('/api/v1/uae-en/classifieds/map?bounds=24.0,54.0,24.9,54.9')->assertOk()
            ->assertJsonPath('type', 'FeatureCollection')->assertJsonPath('features.0.properties.number', $old->number)
            ->assertJsonPath('features.0.geometry.coordinates', [54.37, 24.45]);
        $this->getJson('/api/v1/uae-en/classifieds/categories/cars/attributes')->assertOk()
            ->assertJsonPath('data.vertical', 'motors')->assertJsonCount(3, 'data.attributes');
    });

    it('lists the category tree with ids so the post-ad wizard can send category_id', function () {
        $this->getJson('/api/v1/uae-en/classifieds/categories')->assertOk()
            ->assertJsonPath('data.0.id', $this->motors->id)->assertJsonPath('data.0.slug', 'motors')
            ->assertJsonPath('data.0.children.0.id', $this->cars->id)->assertJsonPath('data.0.children.0.slug', 'cars');
    });

    it('compares live ads of one category and keeps every country to its own ads', function () {
        $a = liveAd(data: ['specs' => ['year' => 2020, 'make' => 'toyota']]);
        $b = liveAd(data: ['specs' => ['year' => 2018, 'make' => 'bmw']]);

        $this->getJson("/api/v1/uae-en/classifieds/compare?numbers[]={$a->number}&numbers[]={$b->number}")->assertOk()
            ->assertJsonCount(2, 'data.ads')->assertJsonPath('data.attributes.0.key', 'year');
        $this->getJson("/api/v1/omn-en/classifieds/ads/{$a->number}")->assertNotFound();
        expect($this->getJson('/api/v1/omn-en/classifieds/ads')->json('meta.total'))->toBe(0);
    });
});

describe('inquiries, phone reveal and the customer purchase contract (FR-CLS-4)', function () {
    it('takes guest inquiries and opens chat both ways', function () {
        $owner = User::factory()->create();
        $ad = liveAd($owner);
        [$buyer, $token] = customerWithToken();
        $buyer->update(['phone' => '050 222 3333']);
        $guest = ['message' => 'Still available?', 'name' => 'Guest Ali', 'email' => 'ali@example.com'];

        $this->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/inquiries", $guest)->assertUnprocessable()->assertJsonValidationErrors('phone', 'error.fields');
        $this->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/inquiries", $guest + ['phone' => '+968 9123 4567'])->assertUnprocessable()->assertJsonValidationErrors('phone', 'error.fields');
        $this->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/inquiries", $guest + ['phone' => '0501111111'])->assertCreated();
        Notification::assertSentTo($owner, EventEmail::class, fn (EventEmail $mail): bool => $mail->event === 'classified.new_message' && str_contains(json_encode($mail->options['blocks']), '+971501111111'));
        Notification::assertSentOnDemand(EventEmail::class, fn (EventEmail $mail, array $channels, object $notifiable): bool => $mail->event === 'classified.inquiry_sent' && array_key_first((array) $notifiable->routes['mail']) === 'ali@example.com');

        $thread = $this->withToken($token)->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/inquiries", ['message' => 'Best price?'])->assertCreated()->json('data.thread_id');
        expect(ClassifiedThread::query()->findOrFail($thread))->contact_phone->toBe('+971502223333')->contact_email->toBe($buyer->email);
        $this->withToken($token)->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/inquiries", ['message' => 'Hello again'])->assertCreated()->assertJsonPath('data.thread_id', $thread);

        app('auth')->forgetGuards();
        $ownerToken = $owner->createToken('t')->plainTextToken;
        $this->withToken($ownerToken)->getJson('/api/v1/uae-en/classifieds/threads')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.role', 'seller');
        $this->withToken($ownerToken)->postJson("/api/v1/uae-en/classifieds/threads/{$thread}/messages", ['body' => '185k final'])->assertCreated()->assertJsonPath('data.from', 'seller');
        $this->withToken($ownerToken)->getJson("/api/v1/uae-en/classifieds/threads/{$thread}")->assertOk()->assertJsonPath('data.status', 'contacted')->assertJsonCount(3, 'data.messages');

        app('auth')->forgetGuards();
        [, $strangerToken] = customerWithToken();
        $this->withToken($strangerToken)->getJson("/api/v1/uae-en/classifieds/threads/{$thread}")->assertNotFound();
    });

    it('refuses inquiries and phone reveals until the purchase contract is accepted, and logs every reveal', function () {
        $version = classifiedContract(ContractKind::CustomerPurchase, $this->motors);
        $ad = liveAd();
        [$buyer, $token] = customerWithToken();

        $this->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/inquiries", ['message' => 'Hi', 'name' => 'Guest', 'email' => 'g@example.com', 'phone' => '0501234567'])
            ->assertStatus(409)->assertJsonPath('error.code', 'customer_contract_required')->assertJsonPath('error.contracts.0.version_id', $version->id);
        $this->withToken($token)->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/phone")->assertStatus(409);
        expect(PhoneReveal::query()->count())->toBe(0);

        $this->withToken($token)->postJson("/api/v1/uae-en/contracts/{$version->id}/accept", ['full_name' => 'Sara Buyer', 'accept' => true])->assertSuccessful();
        $this->withToken($token)->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/phone")->assertOk()->assertJsonPath('data.phone', '+971500000009');
        $this->withToken($token)->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/inquiries", ['message' => 'Viewing tomorrow?', 'phone' => '0501234567'])->assertCreated();

        expect(PhoneReveal::query()->sole())->customer_user_id->toBe($buyer->id)->classified_ad_id->toBe($ad->id);
        app('auth')->forgetGuards();
        $this->withoutToken()->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/phone")->assertUnauthorized();
    });
});

describe('mark sold with attribution', function () {
    it('offers the referring marketers with their inquiry counts and records the final price', function () {
        $owner = User::factory()->create();
        $ad = liveAd($owner);
        [, $affiliate] = marketerAccount(['affiliate'], attributes: ['display_name' => 'Aff One']);
        [, $other] = marketerAccount(['affiliate'], attributes: ['display_name' => 'Aff Two']);
        [, $t1] = customerWithToken();
        [, $t2] = customerWithToken();
        $this->withToken($t1)->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/inquiries", ['message' => 'Hi', 'ref' => $affiliate->slug, 'phone' => '0501234567'])->assertCreated();
        app('auth')->forgetGuards();
        $this->withToken($t2)->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/inquiries", ['message' => 'Hi', 'ref' => $affiliate->slug, 'phone' => '0501234567'])->assertCreated();
        app('auth')->forgetGuards();

        $ownerToken = $owner->createToken('t')->plainTextToken;
        $this->withToken($ownerToken)->getJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/referrers")->assertOk()
            ->assertJsonPath('data.0.marketer_profile_id', $affiliate->id)->assertJsonPath('data.0.inquiries', 2)->assertJsonCount(1, 'data');
        $this->withToken($ownerToken)->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/sold", ['final_price' => '180000', 'marketer_profile_id' => $other->id])->assertStatus(422);
        $this->withToken($ownerToken)->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/sold", ['marketer_profile_id' => $affiliate->id])->assertUnprocessable();
        $this->withToken($ownerToken)->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/sold", ['final_price' => '180000', 'marketer_profile_id' => $affiliate->id, 'buyer_note' => 'Cash'])
            ->assertOk()->assertJsonPath('data.status', 'sold');

        expect($ad->refresh())->sold_marketer_profile_id->toBe($affiliate->id)->and((string) $ad->sold_price)->toBe('180000.0000');

        $this->withToken($ownerToken)->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/unsold")->assertOk()->assertJsonPath('data.status', 'active');
        expect($ad->refresh()->sold_marketer_profile_id)->toBeNull();
    });
});

describe('expiry, renewal and packages', function () {
    it('reminds once per threshold, expires after the period and renews for free', function () {
        $owner = User::factory()->create();
        $ad = liveAd($owner);

        $this->travel(27)->days();
        $this->artisan('nawy:classifieds:expire')->assertSuccessful();
        $this->artisan('nawy:classifieds:expire')->assertSuccessful();
        Notification::assertSentToTimes($owner, ClassifiedRenewalReminder::class, 1);

        $this->travel(2)->days();
        $this->artisan('nawy:classifieds:expire')->assertSuccessful();
        Notification::assertSentToTimes($owner, ClassifiedRenewalReminder::class, 2);

        $this->travel(2)->days();
        $this->artisan('nawy:classifieds:expire')->assertSuccessful();
        expect($ad->refresh()->status)->toBe(ClassifiedAdStatus::Expired);

        app(ClassifiedAdMachine::class)->renew($ad, ActorType::Customer, $owner->id);
        expect($ad->refresh())->status->toBe(ClassifiedAdStatus::Active)->and($ad->expires_at->isFuture())->toBeTrue()->and($ad->reminders_sent)->toBe([]);
    });

    it('needs a republish package to renew when renewal is paid', function () {
        $this->cars->update(['renewal_price' => '15']);
        $ad = liveAd();
        $ad->forceFill(['expires_at' => now()->subMinute()])->save();
        app(ClassifiedAdMachine::class)->expireDue();

        expect(fn () => app(ClassifiedAdMachine::class)->renew($ad->refresh(), ActorType::Customer, 1))->toThrow(TransitionRefused::class, 'republish package');
    });

    it('sells a featured package for Nawy Credits, books ad revenue with VAT and puts the ad first', function () {
        [$owner, $token] = customerWithToken();
        $ad = liveAd($owner);
        $other = liveAd();
        ClassifiedPackage::query()->create(['code' => 'featured-7', 'type' => 'featured', 'name_en' => 'Featured 7 days', 'name_ar' => 'مميز', 'duration_days' => 7, 'price' => '21.00']);
        giveCredits($owner->id, '50.00');

        $this->withToken($token)->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/packages", ['package' => 'featured-7', 'payment_method' => 'wallet'])->assertCreated()
            ->assertJsonPath('data.purchase.status', 'paid');

        expect($ad->refresh()->featured_until->isFuture())->toBeTrue()
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::AdRevenue)))->toBe('20.00')
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::VatPayable)))->toBe('1.00');
        assertLedgerBalanced();
        expect($this->getJson('/api/v1/uae-en/classifieds/ads')->json('data.0.number'))->toBe($ad->number)
            ->and($this->getJson('/api/v1/uae-en/classifieds/ads')->json('data.0.is_featured'))->toBeTrue()
            ->and($other->number)->not->toBe($ad->number);
    });
});

describe('saved searches, favourites and reports', function () {
    it('alerts a saved search once for new matching ads', function () {
        [$customer, $token] = customerWithToken();
        $this->withToken($token)->postJson('/api/v1/uae-en/classifieds/saved-searches', ['name' => 'Toyotas', 'filters' => ['category' => 'cars', 'attr' => ['make' => 'toyota']], 'alert_frequency' => 'instant'])->assertCreated();

        $this->travel(1)->minutes();
        liveAd(data: ['specs' => ['year' => 2020, 'make' => 'toyota']]);
        liveAd(data: ['title' => 'A BMW not matching', 'specs' => ['year' => 2020, 'make' => 'bmw']]);
        $this->travel(1)->minutes();

        $this->artisan('nawy:classifieds:saved-search-alerts')->assertSuccessful();
        $this->artisan('nawy:classifieds:saved-search-alerts')->assertSuccessful();

        Notification::assertSentToTimes($customer, SavedSearchMatches::class, 1);
        Notification::assertSentTo($customer, SavedSearchMatches::class, fn (SavedSearchMatches $alert): bool => $alert->count === 1);
        expect(SavedSearch::query()->sole()->last_alerted_at)->not->toBeNull();
    });

    it('keeps favourites per customer and archives an ad when a report is actioned', function () {
        $ad = liveAd();
        [, $token] = customerWithToken();
        $this->withToken($token)->postJson("/api/v1/uae-en/classifieds/favourites/{$ad->number}")->assertOk();
        $this->withToken($token)->getJson('/api/v1/uae-en/classifieds/favourites')->assertOk()->assertJsonPath('data.0.number', $ad->number);
        // QC-061: the ad detail counts its saves for the "Save (n)" button.
        $this->getJson("/api/v1/uae-en/classifieds/ads/{$ad->number}")->assertOk()->assertJsonPath('data.saves', 1);

        $this->withToken($token)->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/reports", ['reason' => 'fraud', 'details' => 'Asks for a deposit'])->assertCreated();
        $this->withToken($token)->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/reports", ['reason' => 'fraud'])->assertStatus(422);
        $this->withToken($token)->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/reports", ['reason' => 'nonsense'])->assertStatus(422);

        app(ClassifiedReports::class)->decide(ClassifiedReport::query()->sole(), ReportStatus::Actioned, 1, 'Scam');

        expect($ad->refresh()->status)->toBe(ClassifiedAdStatus::Archived);
        $this->getJson("/api/v1/uae-en/classifieds/ads/{$ad->number}")->assertNotFound();
    });
});
