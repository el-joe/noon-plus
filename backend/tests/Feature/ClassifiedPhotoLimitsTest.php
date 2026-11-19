<?php

use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Classifieds\Models\ClassifiedCategory;
use App\Domains\Classifieds\Support\ClassifiedAdMachine;
use App\Domains\Classifieds\Support\ClassifiedAds;
use App\Domains\Orders\Enums\ActorType;
use App\Models\User;
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
    $this->property = ClassifiedCategory::query()->create(['slug' => 'property', 'name_en' => 'Property', 'name_ar' => 'عقارات', 'vertical' => 'property', 'photo_max_kb' => 300]);
    $this->flats = ClassifiedCategory::query()->create(['parent_id' => $this->property->id, 'slug' => 'flats', 'name_en' => 'Flats', 'name_ar' => 'شقق', 'max_photos' => 2]);
    $this->misc = ClassifiedCategory::query()->create(['slug' => 'misc', 'name_en' => 'Misc', 'name_ar' => 'متفرقات']);
});

it('lists max_photos and photo_max_kb per category with inheritance and defaults', function () {
    $this->getJson('/api/v1/uae-en/classifieds/categories')->assertOk()
        ->assertJsonPath('data.0.slug', 'property')->assertJsonPath('data.0.max_photos', 20)->assertJsonPath('data.0.photo_max_kb', 300)
        ->assertJsonPath('data.0.children.0.max_photos', 2)->assertJsonPath('data.0.children.0.photo_max_kb', 300)
        ->assertJsonPath('data.1.slug', 'misc')->assertJsonPath('data.1.photo_max_kb', 8192);

    $this->getJson('/api/v1/uae-en/classifieds/categories/flats/attributes')->assertOk()
        ->assertJsonPath('data.max_photos', 2)->assertJsonPath('data.photo_max_kb', 300)
        ->assertJsonPath('data.allows_video', true)->assertJsonPath('data.video_max_seconds', 60)->assertJsonPath('data.video_max_kb', 51200);
});

it('enforces the category photo size and count on upload', function () {
    [, $token] = customerWithToken();
    $number = $this->withToken($token)->postJson('/api/v1/uae-en/classifieds/ads', ['category_id' => $this->flats->id, 'title' => 'Two-bed flat in Marina', 'price' => '90000', 'city' => 'Dubai'])->assertCreated()->json('data.number');
    $upload = fn (UploadedFile $file) => $this->withToken($token)->post("/api/v1/uae-en/classifieds/ads/{$number}/photos", ['photo' => $file], ['Accept' => 'application/json']);

    $upload(UploadedFile::fake()->image('big.jpg')->size(400))->assertUnprocessable()->assertJsonValidationErrors(['photo'], 'error.fields');
    $upload(UploadedFile::fake()->image('a.jpg')->size(200))->assertCreated();
    $upload(UploadedFile::fake()->image('b.jpg')->size(200))->assertCreated();
    $upload(UploadedFile::fake()->image('c.jpg')->size(200))->assertUnprocessable();
    expect(ClassifiedAd::query()->where('number', $number)->sole()->media()->count())->toBe(2);
});

it('lets an admin edit the limits of a category, empty meaning inherit or default', function () {
    $admin = adminAccount('uae', 'operations');
    $this->actingAs($admin)->get(onPanel('admin', 'classifieds/categories'))->assertOk()->assertSee('2 photos · 300 KB each');
    $this->actingAs($admin)->post(onPanel('admin', "classifieds/categories/{$this->flats->id}/limits"), ['max_photos' => 8, 'photo_max_kb' => 1024])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->flats->refresh()->photoLimits())->toBe(['max_photos' => 8, 'photo_max_kb' => 1024]);

    $this->actingAs($admin)->post(onPanel('admin', "classifieds/categories/{$this->flats->id}/limits"), ['max_photos' => '', 'photo_max_kb' => ''])->assertRedirect();
    expect($this->flats->refresh()->photoLimits())->toBe(['max_photos' => 20, 'photo_max_kb' => 300]);
    $this->actingAs($admin)->post(onPanel('admin', "classifieds/categories/{$this->flats->id}/limits"), ['photo_max_kb' => 99999])->assertSessionHasErrors('photo_max_kb');
});

it('adds photo, place and property facts to the map features', function () {
    $owner = User::factory()->create();
    $ad = app(ClassifiedAds::class)->create($owner->id, ['category_id' => $this->misc->id, 'title' => 'Desk for sale cheap', 'price' => '100', 'city' => 'Dubai', 'area' => 'Marina', 'latitude' => 25.08, 'longitude' => 55.14, 'contact_phone' => '+971500000009']);
    app(ClassifiedAds::class)->addPhoto($ad, UploadedFile::fake()->image('desk.jpg'));
    app(ClassifiedAdMachine::class)->submit($ad->refresh(), ActorType::Customer, $owner->id);
    app(ClassifiedAdMachine::class)->approve($ad->refresh(), 1);

    $feature = $this->getJson('/api/v1/uae-en/classifieds/map')->assertOk()->json('features.0.properties');
    expect($feature['number'])->toBe($ad->number)->and($feature['city'])->toBe('Dubai')->and($feature['area'])->toBe('Marina')
        ->and($feature['image'])->toContain('/')->and($feature)->toHaveKey('property');
});
