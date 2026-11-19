<?php

use App\Domains\Cart\Models\Cart;
use App\Domains\Newsletter\Models\NewsletterSubscription;
use App\Domains\Newsletter\Notifications\ConfirmNewsletterSubscription;
use App\Domains\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;

/*
| Storefront API basics (WP9): G-W7 sign-out, G-W8 CORS exposed headers,
| G-W9 guest cart reads, G-W11 error envelope, G-W12 newsletter double opt-in.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->actAsCountry('uae');
});

it('signs out by revoking only the current token', function () {
    [$customer, $token] = customerWithToken();
    $customer->createToken('phone');

    $this->withToken($token)->postJson('/api/v1/uae-en/auth/logout')->assertOk()->assertJsonPath('data.signed_out', true);

    expect(PersonalAccessToken::findToken($token))->toBeNull()
        ->and($customer->tokens()->count())->toBe(1);
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/uae-en/me')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
});

it('exposes the guest cart token to browsers on other origins', function () {
    $this->withHeaders(['Origin' => 'http://localhost:3000'])->getJson('/api/v1/uae-en/cart')
        ->assertOk()
        ->assertHeader('Access-Control-Expose-Headers', 'X-Cart-Token, X-Booking-Token, X-Application-Token, Retry-After, Content-Disposition');
});

it('answers a guest cart read without a token with an empty cart and no new cart row', function () {
    $this->getJson('/api/v1/uae-en/cart')->assertOk()
        ->assertJsonPath('data.shipments', [])
        ->assertJsonPath('data.totals.total.amount', '0.00')
        ->assertHeaderMissing('X-Cart-Token');
    $this->withHeader('X-Cart-Token', 'unknown-token')->getJson('/api/v1/uae-en/cart')->assertOk()->assertHeaderMissing('X-Cart-Token');

    expect(Cart::query()->count())->toBe(0);

    $listing = sellableListing('10.00');
    $token = $this->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1])->assertOk()->headers->get('X-Cart-Token');
    $this->withHeader('X-Cart-Token', $token)->getJson('/api/v1/uae-en/cart')->assertHeader('X-Cart-Token', $token)->assertJsonPath('data.shipments.0.items.0.quantity', 1);
    expect(Cart::query()->count())->toBe(1);
});

it('renders framework errors on the API in the error envelope', function () {
    $this->getJson('/api/v1/uae-en/no-such-route')->assertNotFound()
        ->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'Not found.', 'fields' => []]]);
    $this->getJson('/api/v1/xxx-en/products')->assertNotFound()->assertJsonPath('error.code', 'not_found');
    $this->getJson('/api/v1/uae-en/products/missing-product')->assertNotFound()->assertJsonPath('error.code', 'not_found');
    $this->getJson('/api/v1/uae-en/wallet')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    $this->deleteJson('/api/v1/uae-en/products')->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    $this->getJson('/api/v1/uae-en/products?sort=weird')->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['code', 'message', 'fields' => ['sort']]]);

    foreach (range(1, 10) as $attempt) {
        $this->postJson('/api/v1/uae-en/newsletter/subscribe', ['email' => "a{$attempt}@example.com"]);
    }

    $this->postJson('/api/v1/uae-en/newsletter/subscribe', ['email' => 'late@example.com'])->assertStatus(429)
        ->assertJsonPath('error.code', 'too_many_requests')
        ->assertHeader('Retry-After');
});

it('subscribes to the newsletter with double opt-in and never tells whether the email was known', function () {
    Notification::fake();

    $this->postJson('/api/v1/uae-ar/newsletter/subscribe', ['email' => 'Sara@Example.com', 'source' => 'footer'])->assertStatus(202)->assertJsonPath('data.status', 'confirmation_sent');

    $subscription = NewsletterSubscription::query()->sole();
    expect($subscription->status)->toBe('pending')->and($subscription->email)->toBe('sara@example.com')->and($subscription->locale)->toBe('ar');

    $url = null;
    Notification::assertSentOnDemand(ConfirmNewsletterSubscription::class, function (ConfirmNewsletterSubscription $notification, array $channels, AnonymousNotifiable $notifiable) use (&$url): bool {
        $url = $notification->confirmUrl;

        return $notifiable->routes['mail'] === 'sara@example.com';
    });
    expect($url)->toStartWith(config('nawy.payments.storefront_url').'/uae-ar/newsletter/confirm?token=');
    $token = substr($url, strpos($url, 'token=') + 6);

    $this->postJson('/api/v1/uae-en/newsletter/confirm', ['token' => str_repeat('x', 48)])->assertNotFound()->assertJsonPath('error.code', 'invalid_token');
    $this->postJson('/api/v1/uae-en/newsletter/confirm', ['token' => $token])->assertOk()->assertJsonPath('data.status', 'subscribed');
    expect($subscription->fresh()->status)->toBe('confirmed');

    // Subscribing again: same answer, no second email.
    $this->postJson('/api/v1/uae-en/newsletter/subscribe', ['email' => 'sara@example.com'])->assertStatus(202)->assertJsonPath('data.status', 'confirmation_sent');
    Notification::assertSentOnDemandTimes(ConfirmNewsletterSubscription::class, 1);

    $this->postJson('/api/v1/uae-en/newsletter/unsubscribe', ['token' => $token])->assertOk()->assertJsonPath('data.status', 'unsubscribed');
    expect($subscription->fresh()->status)->toBe('unsubscribed');
    $this->postJson('/api/v1/uae-en/newsletter/confirm', ['token' => $token])->assertNotFound();
});

it('keeps newsletter subscriptions per country and can skip the opt-in email by setting', function () {
    Notification::fake();
    $oman = $this->actAsCountry('omn');
    app(SettingsService::class)->set('newsletter.double_opt_in', false, $oman);

    $this->postJson('/api/v1/omn-en/newsletter/subscribe', ['email' => 'ali@example.com'])->assertStatus(202)->assertJsonPath('data.status', 'subscribed');
    expect(NewsletterSubscription::query()->sole()->status)->toBe('confirmed');
    Notification::assertNothingSent();

    $this->actAsCountry('uae');
    expect(NewsletterSubscription::query()->count())->toBe(0);
    $this->postJson('/api/v1/uae-en/newsletter/subscribe', ['email' => 'not-an-email'])->assertUnprocessable()->assertJsonValidationErrors(['email'], 'error.fields');
});
