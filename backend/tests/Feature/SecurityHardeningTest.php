<?php

use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Identity\Notifications\EmailLoginCodeNotification;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/*
| WP11 hardening: findings of the security review, one test each.
*/

beforeEach(function () {
    $this->setUpCountries();
    $this->actAsCountry('uae');
    $this->withoutVite();
    Cache::flush();
});

it('asks for the two-step code before the ops app API issues a token', function () {
    Notification::fake();
    [$user] = sellerAccount('uae');
    $user->forceFill(['two_factor_enabled' => true])->save();
    $login = ['email' => $user->email, 'password' => 'password', 'device_name' => 'Pixel', 'role' => 'vendor'];

    $this->postJson('/api/v1/uae-en/ops/auth/login', $login)
        ->assertStatus(202)->assertJsonPath('data.two_factor_required', true)->assertJsonMissingPath('data.token');
    expect($user->tokens()->count())->toBe(0);

    $code = null;
    Notification::assertSentOnDemand(EmailLoginCodeNotification::class, function (EmailLoginCodeNotification $notification) use (&$code): bool {
        $code = $notification->code;

        return true;
    });

    $this->postJson('/api/v1/uae-en/ops/auth/login', $login + ['two_factor_code' => $code === '999999' ? '999998' : '999999'])
        ->assertStatus(422)->assertJsonPath('error.code', 'two_factor_rejected');
    $this->postJson('/api/v1/uae-en/ops/auth/login', $login + ['two_factor_code' => $code])
        ->assertOk()->assertJsonStructure(['data' => ['token']]);
});

it('keeps a suspended person out of every panel even with an active membership', function () {
    $user = User::factory()->create(['email' => 'banned@nawy.test', 'password' => 'correct-horse-battery', 'status' => 'suspended']);
    $user->memberships()->create(['role' => MembershipRole::Admin]);
    $admin = 'http://admin.'.config('nawy.base_domain');

    $this->post($admin.'/login', ['email' => 'banned@nawy.test', 'password' => 'correct-horse-battery'])->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->actingAs($user)->get($admin.'/')->assertForbidden();
    $this->assertGuest();
});

it('sends security headers on API and panel responses', function () {
    $this->getJson('/api/v1/uae-en/categories')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeaderMissing('Content-Security-Policy');

    $page = $this->get('http://admin.'.config('nawy.base_domain').'/login')->assertOk();
    expect($page->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'");
});

it('limits every API route per user or IP by default', function () {
    config(['nawy.api_rate_per_minute' => 3]);

    foreach (range(1, 3) as $attempt) {
        $this->getJson('/api/v1/uae-en/categories')->assertOk();
    }

    $this->getJson('/api/v1/uae-en/categories')->assertStatus(429);
});

it('spares loopback callers from the API limit only in a local app', function () {
    config(['nawy.api_rate_per_minute' => 2]);
    app()->detectEnvironment(fn (): string => 'local');

    try {
        foreach (range(1, 4) as $attempt) {
            $this->getJson('/api/v1/uae-en/categories')->assertOk();
        }

        $remote = ['REMOTE_ADDR' => '203.0.113.7'];
        $this->withServerVariables($remote)->getJson('/api/v1/uae-en/categories')->assertOk();
        $this->withServerVariables($remote)->getJson('/api/v1/uae-en/categories')->assertOk();
        $this->withServerVariables($remote)->getJson('/api/v1/uae-en/categories')->assertStatus(429);
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }

    foreach (range(1, 2) as $attempt) {
        $this->withServerVariables([])->getJson('/api/v1/uae-en/categories')->assertOk();
    }

    $this->getJson('/api/v1/uae-en/categories')->assertStatus(429);
});

it('refuses to boot production with a fake payment or social driver', function () {
    $provider = new AppServiceProvider(app());
    $guard = new ReflectionMethod($provider, 'refuseTestDriversInProduction');
    app()->detectEnvironment(fn (): string => 'production');

    try {
        config(['nawy.payments.driver' => 'http', 'nawy.customer_auth.social_driver' => 'live']);
        $guard->invoke($provider);

        config(['nawy.payments.driver' => 'fake']);
        expect(fn () => $guard->invoke($provider))->toThrow(RuntimeException::class, 'NAWY_PAYMENTS_DRIVER');

        config(['nawy.payments.driver' => 'http', 'nawy.customer_auth.social_driver' => 'fake']);
        expect(fn () => $guard->invoke($provider))->toThrow(RuntimeException::class, 'NAWY_SOCIAL_AUTH_DRIVER');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
});
