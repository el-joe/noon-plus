<?php

use App\Domains\Contracts\Enums\ContractKind;
use App\Domains\Contracts\Models\ContractTemplate;
use App\Domains\Contracts\Support\ContractPublisher;
use App\Domains\Identity\Notifications\EmailLoginCodeNotification;
use Database\Seeders\AdminRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
});

it('QC-017: the vendor ops app is gated by the Seller Agreement until the owner signs it in the app', function () {
    [$owner, $vendor] = sellerAccount('uae', ['seller_agreement_accepted_at' => null]);
    $template = ContractTemplate::query()->create(['country_id' => $this->country->id, 'kind' => ContractKind::SellerPlatform, 'name' => 'Seller Agreement']);
    $version = app(ContractPublisher::class)->publish(app(ContractPublisher::class)->draft($template, ['body_ar' => 'اتفاقية', 'body_en' => 'Agreement for {store_name}']));
    $token = $owner->createToken('app', ['ops:vendor'])->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/uae-en/ops/vendor/orders')->assertForbidden()->assertJsonPath('error.code', 'seller_agreement_required');
    $this->withToken($token)->getJson('/api/v1/uae-en/ops/vendor/agreement')->assertOk()
        ->assertJsonPath('data.signed', false)->assertJsonPath('data.can_sign', true)
        ->assertJsonPath('data.version.text', 'Agreement for '.$vendor->store_name);
    $this->withToken($token)->postJson('/api/v1/uae-en/ops/vendor/agreement/sign', ['version_id' => $version->id, 'full_name' => 'Owner Name', 'accept' => true])->assertOk();
    $this->withToken($token)->getJson('/api/v1/uae-en/ops/vendor/orders')->assertOk();
});

it('QA-013: validates the inline checkout phone for the country and stores it normalised', function () {
    [, $token] = customerWithToken();
    $listing = sellableListing('100.00', stock: 5);
    $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1])->assertOk();
    $address = ['name' => 'QA', 'city' => 'Dubai', 'area' => 'Marina', 'street' => 'S'];

    $this->withToken($token)->postJson('/api/v1/uae-en/orders', ['payment_method' => 'cod', 'address' => $address + ['phone' => 'abc']])
        ->assertUnprocessable()->assertJsonValidationErrors(['address.phone'], 'error.fields');
    $this->withToken($token)->postJson('/api/v1/uae-en/orders', ['payment_method' => 'cod', 'address' => $address + ['phone' => '0501234567']])
        ->assertCreated()->assertJsonPath('data.shipping_address.phone', '+971501234567');
});

it('QA-022: customer mail is branded Nawy', function () {
    Notification::fake();
    $this->postJson('/api/v1/uae-en/auth/email-code/send', ['email' => 'sara@example.com'])->assertSuccessful();

    Notification::assertSentOnDemand(EmailLoginCodeNotification::class, function (EmailLoginCodeNotification $notification): bool {
        $html = (string) $notification->toMail(new AnonymousNotifiable)->render();

        return config('app.name') === 'Nawy' && config('mail.from.name') === 'Nawy' && ! str_contains($html, 'Laravel');
    });
});

it('QA-027 / QA-028: array query parameters and flashed arrays never break panel pages', function () {
    $admin = adminAccount();
    [$seller] = sellerAccount();

    $this->actingAs($admin)->get(onPanel('admin', 'documents?status[]=x'))->assertOk();
    $this->actingAs($admin)->get(onPanel('admin', 'marketers?q[]=x&status[]=x'))->assertOk();
    $this->actingAs($admin)->get(onPanel('admin', 'orders?q[]=x'))->assertOk();
    $this->actingAs($seller)->get(onPanel('partner', 'campaigns?status[]=x'))->assertOk();
    $this->actingAs($seller)->withSession(['_old_input' => ['q' => ['x']]])->get(onPanel('partner', 'products'))->assertOk();
});

it('QA-029: a migration seeds the review moderation permissions on an existing database', function () {
    $this->seed(AdminRoleSeeder::class);
    Permission::query()->whereIn('name', ['reviews.view', 'reviews.moderate', 'product_qa.moderate'])->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $migration = require database_path('migrations/2026_11_25_100000_seed_review_moderation_permissions.php');
    $migration->up();

    expect(Role::query()->where('name', 'support')->sole()->hasPermissionTo('reviews.view'))->toBeTrue();
    $support = adminAccount('uae', 'support');
    $this->actingAs($support)->get(onPanel('admin', 'reviews'))->assertOk();
});
