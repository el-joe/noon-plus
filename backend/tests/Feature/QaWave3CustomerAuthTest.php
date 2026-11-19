<?php

use App\Domains\Identity\Enums\LoginMethod;
use App\Domains\Identity\Models\LoginEvent;
use App\Domains\Identity\Models\SocialAccount;
use App\Domains\Identity\Notifications\CustomerPasswordResetNotification;
use App\Domains\Identity\Support\Jwt\Base64Url;
use App\Domains\Identity\Support\Social\AppleProvider;
use App\Domains\Identity\Support\Social\FakeSocialProvider;
use App\Domains\Identity\Support\Social\GoogleProvider;
use App\Models\User;
use Database\Seeders\CountrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(CountrySeeder::class);
    Notification::fake();
});

/**
 * A passwordless customer, as the email-code sign-in creates one.
 */
function codeOnlyCustomer(string $email = 'sara@example.com'): User
{
    return User::createPasswordless($email, 'Sara', verified: true);
}

/**
 * @param  array<string, mixed>  $claims
 */
function rsaSignedJwt(array $claims, OpenSSLAsymmetricKey $key, string $kid = 'k1'): string
{
    $input = Base64Url::encode(json_encode(['alg' => 'RS256', 'kid' => $kid, 'typ' => 'JWT'])).'.'.Base64Url::encode(json_encode($claims));
    openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256);

    return $input.'.'.Base64Url::encode($signature);
}

function fakeJwks(string $url, OpenSSLAsymmetricKey $key, string $kid = 'k1'): void
{
    $details = openssl_pkey_get_details($key);
    Http::fake([$url => Http::response(['keys' => [[
        'kty' => 'RSA', 'kid' => $kid, 'alg' => 'RS256', 'use' => 'sig',
        'n' => Base64Url::encode($details['rsa']['n']), 'e' => Base64Url::encode($details['rsa']['e']),
    ]]])]);
}

describe('optional password (D9)', function () {
    it('lets a code-only customer set a password, then sign in with it', function () {
        $user = codeOnlyCustomer();
        $this->postJson('/api/v1/uae-en/auth/password/login', ['email' => 'sara@example.com', 'password' => 'anything-at-all', 'device_name' => 'web'])
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_credentials');

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/uae-en/me/password', ['password' => 'new-secret-1', 'password_confirmation' => 'new-secret-1'])
            ->assertOk()->assertJsonPath('data.has_password', true);
        app('auth')->forgetGuards();

        $this->postJson('/api/v1/uae-en/auth/password/login', ['email' => 'Sara@Example.com', 'password' => 'new-secret-1', 'device_name' => 'web'])
            ->assertOk()->assertJsonPath('data.user.has_password', true)->assertJsonStructure(['data' => ['token']]);
        expect(LoginEvent::query()->where('method', LoginMethod::Password)->pluck('outcome')->map->value->all())
            ->toBe(['wrong_credentials', 'succeeded']);
    });

    it('requires the current password to change it and signs other devices out', function () {
        $user = codeOnlyCustomer();
        $user->forceFill(['password' => 'first-secret'])->save();
        $other = $user->createToken('old phone')->plainTextToken;
        $current = $user->createToken('this phone')->plainTextToken;

        $this->withToken($current)->postJson('/api/v1/uae-en/me/password', ['password' => 'second-secret', 'password_confirmation' => 'second-secret'])
            ->assertStatus(422);
        $this->withToken($current)->postJson('/api/v1/uae-en/me/password', ['current_password' => 'wrong', 'password' => 'second-secret', 'password_confirmation' => 'second-secret'])
            ->assertStatus(422)->assertJsonPath('error.fields.current_password.0', 'The current password is not correct.');
        $this->withToken($current)->postJson('/api/v1/uae-en/me/password', ['current_password' => 'first-secret', 'password' => 'second-secret', 'password_confirmation' => 'second-secret'])
            ->assertOk();

        expect($user->tokens()->pluck('name')->all())->toBe(['this phone']);
        app('auth')->forgetGuards();
        $this->withToken($other)->getJson('/api/v1/uae-en/me')->assertUnauthorized();
    });

    it('resets a forgotten password with the emailed code or link, and hides unknown emails', function () {
        $user = codeOnlyCustomer();
        $this->postJson('/api/v1/uae-en/auth/password/forgot', ['email' => 'nobody@example.com'])->assertStatus(202);
        Notification::assertNothingSent();

        $this->postJson('/api/v1/uae-en/auth/password/forgot', ['email' => 'sara@example.com'])->assertStatus(202);
        $mail = null;
        Notification::assertSentTo($user, CustomerPasswordResetNotification::class, function ($notification) use (&$mail): bool {
            $mail = $notification;

            return true;
        });
        expect($mail->url)->toStartWith(config('nawy.payments.storefront_url').'/uae-en/reset-password?email=sara%40example.com&token=');

        $this->postJson('/api/v1/uae-en/auth/password/reset', ['email' => 'sara@example.com', 'code' => '000000', 'password' => 'reset-secret', 'password_confirmation' => 'reset-secret'])
            ->assertStatus(422)->assertJsonPath('error.code', 'code_invalid');
        $this->postJson('/api/v1/uae-en/auth/password/reset', ['email' => 'sara@example.com', 'code' => $mail->code, 'password' => 'reset-secret', 'password_confirmation' => 'reset-secret', 'device_name' => 'iPhone'])
            ->assertOk()->assertJsonPath('data.reset', true)->assertJsonStructure(['data' => ['token', 'user']]);
        expect($user->refresh()->hasChosenPassword())->toBeTrue();

        parse_str(parse_url($mail->url, PHP_URL_QUERY), $query);
        $this->postJson('/api/v1/uae-en/auth/password/reset', ['email' => 'sara@example.com', 'token' => 'not-the-token', 'password' => 'link-secret-1', 'password_confirmation' => 'link-secret-1'])
            ->assertStatus(422)->assertJsonPath('error.code', 'reset_token_invalid');
        // The code reset above already used up the link.
        $this->postJson('/api/v1/uae-en/auth/password/reset', ['email' => 'sara@example.com', 'token' => $query['token'], 'password' => 'link-secret-1', 'password_confirmation' => 'link-secret-1'])
            ->assertStatus(422);
    });

    it('resets with the emailed link token', function () {
        $user = codeOnlyCustomer();
        $this->postJson('/api/v1/uae-en/auth/password/forgot', ['email' => 'sara@example.com'])->assertStatus(202);
        $url = null;
        Notification::assertSentTo($user, CustomerPasswordResetNotification::class, function ($notification) use (&$url): bool {
            $url = $notification->url;

            return true;
        });
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->postJson('/api/v1/uae-en/auth/password/reset', ['email' => 'sara@example.com', 'token' => $query['token'], 'password' => 'link-secret-1', 'password_confirmation' => 'link-secret-1'])
            ->assertOk()->assertJsonMissingPath('data.token');
        $this->postJson('/api/v1/uae-en/auth/password/login', ['email' => 'sara@example.com', 'password' => 'link-secret-1', 'device_name' => 'web'])->assertOk();
    });

    it('throttles password sign-in attempts per email', function () {
        codeOnlyCustomer();

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/uae-en/auth/password/login', ['email' => 'sara@example.com', 'password' => 'guess-'.$attempt, 'device_name' => 'web'])->assertStatus(422);
        }

        $this->postJson('/api/v1/uae-en/auth/password/login', ['email' => 'sara@example.com', 'password' => 'guess-6', 'device_name' => 'web'])->assertStatus(429);
    });
});

describe('Google and Apple sign-in (D9)', function () {
    it('opens a verified account from an app identity token and signs the same subject in again', function () {
        $token = FakeSocialProvider::token(['sub' => 'g-123', 'email' => 'New@Example.com', 'email_verified' => true, 'name' => 'New Person']);

        $this->postJson('/api/v1/uae-en/auth/social/google/token', ['id_token' => $token, 'device_name' => 'Pixel'])
            ->assertOk()->assertJsonPath('data.user.email', 'new@example.com')->assertJsonPath('data.user.has_password', false);
        $user = User::query()->where('email', 'new@example.com')->sole();
        expect($user->name)->toBe('New Person')->and($user->email_verified_at)->not->toBeNull();

        // The provider later reports a different email: the subject still signs in to the same account.
        $again = FakeSocialProvider::token(['sub' => 'g-123', 'email' => 'renamed@example.com', 'email_verified' => true]);
        $this->postJson('/api/v1/uae-en/auth/social/google/token', ['id_token' => $again, 'device_name' => 'Pixel'])
            ->assertOk()->assertJsonPath('data.user.id', $user->id);
        expect(User::query()->count())->toBe(1)
            ->and(LoginEvent::query()->where('method', 'google')->where('outcome', 'succeeded')->count())->toBe(2);
    });

    it('links to an existing account by verified email only', function () {
        $existing = codeOnlyCustomer();

        $this->postJson('/api/v1/uae-en/auth/social/apple/token', ['id_token' => FakeSocialProvider::token(['sub' => 'a-9', 'email' => 'sara@example.com', 'email_verified' => false]), 'device_name' => 'iPhone'])
            ->assertStatus(422)->assertJsonPath('error.code', 'social_email_unverified');
        expect(SocialAccount::query()->count())->toBe(0);

        $this->postJson('/api/v1/uae-en/auth/social/apple/token', ['id_token' => FakeSocialProvider::token(['sub' => 'a-9', 'email' => 'sara@example.com', 'email_verified' => true]), 'device_name' => 'iPhone'])
            ->assertOk()->assertJsonPath('data.user.id', $existing->id);
        expect($existing->socialAccounts()->pluck('provider')->all())->toBe(['apple']);

        $this->postJson('/api/v1/uae-en/auth/social/apple/token', ['id_token' => 'forged', 'device_name' => 'iPhone'])
            ->assertStatus(422)->assertJsonPath('error.code', 'social_token_invalid');
    });

    it('runs the web redirect, callback and one-time ticket exchange', function () {
        $redirect = $this->getJson('/api/v1/omn-ar/auth/social/google/redirect?return_to=/cart')->assertOk();
        parse_str(parse_url($redirect->json('data.url'), PHP_URL_QUERY), $query);

        $callback = $this->get('/api/v1/auth/social/google/callback?'.http_build_query([
            'state' => $query['state'],
            'code' => FakeSocialProvider::token(['sub' => 'g-7', 'email' => 'web@example.com', 'email_verified' => true]),
        ]));
        $location = $callback->assertRedirect()->headers->get('Location');
        expect($location)->toStartWith(config('nawy.payments.storefront_url').'/omn-ar/auth/social/complete?ticket=')
            ->toContain('return_to=%2Fcart');
        parse_str(parse_url($location, PHP_URL_QUERY), $complete);

        $this->postJson('/api/v1/uae-en/auth/social/exchange', ['ticket' => $complete['ticket'], 'device_name' => 'web'])
            ->assertStatus(422)->assertJsonPath('error.code', 'social_ticket_invalid');
        // RT-008: the wrong-market attempt does not spend the ticket; it is still single-use.
        $this->postJson('/api/v1/omn-ar/auth/social/exchange', ['ticket' => $complete['ticket'], 'device_name' => 'web'])
            ->assertOk()->assertJsonPath('data.user.email', 'web@example.com');
        $this->postJson('/api/v1/omn-ar/auth/social/exchange', ['ticket' => $complete['ticket'], 'device_name' => 'web'])
            ->assertStatus(422)->assertJsonPath('error.code', 'social_ticket_invalid');

        // Replaying the state fails too.
        $this->get('/api/v1/auth/social/google/callback?state='.$query['state'].'&code=x')
            ->assertRedirect(config('nawy.payments.storefront_url').'/login?social_error=social_state_invalid');
    });

    it('exchanges a fresh ticket for a token in the same market', function () {
        $redirect = $this->getJson('/api/v1/omn-ar/auth/social/apple/redirect')->assertOk();
        parse_str(parse_url($redirect->json('data.url'), PHP_URL_QUERY), $query);
        $location = $this->post('/api/v1/auth/social/apple/callback', [
            'state' => $query['state'],
            'code' => FakeSocialProvider::token(['sub' => 'a-1', 'email' => 'web@example.com', 'email_verified' => true]),
        ])->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $complete);

        $this->postJson('/api/v1/omn-ar/auth/social/exchange', ['ticket' => $complete['ticket'], 'device_name' => 'web'])
            ->assertOk()->assertJsonPath('data.user.email', 'web@example.com');
        expect(User::query()->where('email', 'web@example.com')->sole()->memberships()->count())->toBe(1);
    });

    it('returns the app web flow to the app scheme and needs the PKCE verifier to exchange the ticket', function () {
        $verifier = str_repeat('v', 20).'-app-verifier-0123456789abcdef';
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $start = function () use ($challenge): string {
            $redirect = $this->getJson('/api/v1/uae-en/auth/social/google/redirect?code_challenge='.$challenge)->assertOk();
            parse_str(parse_url($redirect->json('data.url'), PHP_URL_QUERY), $query);
            $location = $this->get('/api/v1/auth/social/google/callback?'.http_build_query([
                'state' => $query['state'],
                'code' => FakeSocialProvider::token(['sub' => 'g-app', 'email' => 'app@example.com', 'email_verified' => true]),
            ]))->assertRedirect()->headers->get('Location');
            expect($location)->toStartWith('nawy://auth/social/complete?ticket=')->toContain('market=uae-en');
            parse_str(parse_url($location, PHP_URL_QUERY), $complete);

            return $complete['ticket'];
        };

        // Without (or with a wrong) verifier, the ticket is refused and spent.
        $ticket = $start();
        $this->postJson('/api/v1/uae-en/auth/social/exchange', ['ticket' => $ticket, 'device_name' => 'app'])
            ->assertStatus(422)->assertJsonPath('error.code', 'social_ticket_invalid');
        $this->postJson('/api/v1/uae-en/auth/social/exchange', ['ticket' => $ticket, 'device_name' => 'app', 'code_verifier' => $verifier])
            ->assertStatus(422);

        $ticket = $start();
        $this->postJson('/api/v1/uae-en/auth/social/exchange', ['ticket' => $ticket, 'device_name' => 'app', 'code_verifier' => str_repeat('x', 43)])
            ->assertStatus(422)->assertJsonPath('error.code', 'social_ticket_invalid');

        $ticket = $start();
        $this->postJson('/api/v1/uae-en/auth/social/exchange', ['ticket' => $ticket, 'device_name' => 'app', 'code_verifier' => $verifier])
            ->assertOk()->assertJsonPath('data.user.email', 'app@example.com');
        expect(LoginEvent::query()->latest('id')->first()->method)->toBe(LoginMethod::Google);

        // A provider failure in the app flow goes back to the app too.
        $redirect = $this->getJson('/api/v1/uae-en/auth/social/google/redirect?code_challenge='.$challenge)->assertOk();
        parse_str(parse_url($redirect->json('data.url'), PHP_URL_QUERY), $query);
        $this->get('/api/v1/auth/social/google/callback?'.http_build_query(['state' => $query['state'], 'code' => 'not-a-token']))
            ->assertRedirect('nawy://auth/social/complete?social_error=social_token_invalid');

        $this->getJson('/api/v1/uae-en/auth/social/google/redirect?code_challenge=short')->assertStatus(422);
    });

    it('verifies a real Google identity token against the JWKS', function () {
        config(['nawy.customer_auth.social_driver' => 'live', 'services.google.client_id' => 'web-client', 'services.google.app_client_ids' => ['ios-client']]);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        fakeJwks(GoogleProvider::JWKS_URL, $key);
        $claims = ['iss' => 'https://accounts.google.com', 'aud' => 'ios-client', 'sub' => '1001', 'email' => 'g@example.com', 'email_verified' => true, 'exp' => time() + 600, 'iat' => time()];

        $this->postJson('/api/v1/uae-en/auth/social/google/token', ['id_token' => rsaSignedJwt(['aud' => 'someone-else'] + $claims, $key), 'device_name' => 'iPhone'])
            ->assertStatus(422)->assertJsonPath('error.code', 'social_token_invalid');
        $this->postJson('/api/v1/uae-en/auth/social/google/token', ['id_token' => rsaSignedJwt(['exp' => time() - 3600] + $claims, $key), 'device_name' => 'iPhone'])
            ->assertStatus(422);
        $otherKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->postJson('/api/v1/uae-en/auth/social/google/token', ['id_token' => rsaSignedJwt($claims, $otherKey), 'device_name' => 'iPhone'])
            ->assertStatus(422);

        $this->postJson('/api/v1/uae-en/auth/social/google/token', ['id_token' => rsaSignedJwt($claims, $key), 'device_name' => 'iPhone'])
            ->assertOk()->assertJsonPath('data.user.email', 'g@example.com');
    });

    it('signs the Apple client secret with ES256 and verifies the exchanged identity token', function () {
        $ecKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($ecKey, $ecPem);
        $rsaKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        config([
            'nawy.customer_auth.social_driver' => 'live',
            'services.apple.client_id' => 'com.nawy.web', 'services.apple.team_id' => 'TEAM123', 'services.apple.key_id' => 'KEY123', 'services.apple.private_key' => $ecPem,
        ]);

        $secret = app(AppleProvider::class)->clientSecret();
        [$header, $payload, $signature] = explode('.', $secret);
        $raw = Base64Url::decode($signature);
        $integer = fn (string $bytes): string => "\x02".chr(strlen($bytes = (ord(ltrim($bytes, "\x00")[0]) > 0x7F ? "\x00" : '').ltrim($bytes, "\x00"))).$bytes;
        $body = $integer(substr($raw, 0, 32)).$integer(substr($raw, 32));
        expect(strlen($raw))->toBe(64)
            ->and(openssl_verify($header.'.'.$payload, "\x30".chr(strlen($body)).$body, openssl_pkey_get_details($ecKey)['key'], OPENSSL_ALGO_SHA256))->toBe(1)
            ->and(json_decode(Base64Url::decode($header), true))->toMatchArray(['alg' => 'ES256', 'kid' => 'KEY123'])
            ->and(json_decode(Base64Url::decode($payload), true))->toMatchArray(['iss' => 'TEAM123', 'sub' => 'com.nawy.web', 'aud' => 'https://appleid.apple.com']);

        $redirect = $this->getJson('/api/v1/uae-en/auth/social/apple/redirect')->assertOk();
        parse_str(parse_url($redirect->json('data.url'), PHP_URL_QUERY), $query);
        expect($query)->toMatchArray(['response_mode' => 'form_post', 'client_id' => 'com.nawy.web']);

        $idToken = rsaSignedJwt(['iss' => 'https://appleid.apple.com', 'aud' => 'com.nawy.web', 'sub' => 'apple-77', 'email' => 'relay@privaterelay.appleid.com', 'email_verified' => 'true', 'nonce' => $query['nonce'], 'exp' => time() + 600, 'iat' => time()], $rsaKey);
        $details = openssl_pkey_get_details($rsaKey);
        Http::fake([
            AppleProvider::TOKEN_URL => Http::response(['id_token' => $idToken]),
            AppleProvider::JWKS_URL => Http::response(['keys' => [['kty' => 'RSA', 'kid' => 'k1', 'n' => Base64Url::encode($details['rsa']['n']), 'e' => Base64Url::encode($details['rsa']['e'])]]]),
        ]);

        $location = $this->post('/api/v1/auth/social/apple/callback', ['state' => $query['state'], 'code' => 'auth-code', 'user' => json_encode(['name' => ['firstName' => 'Ali', 'lastName' => 'Saeed']])])
            ->headers->get('Location');
        expect($location)->toContain('/uae-en/auth/social/complete?ticket=')
            ->and(User::query()->where('email', 'relay@privaterelay.appleid.com')->sole()->name)->toBe('Ali Saeed');
        Http::assertSent(fn ($request): bool => $request->url() === AppleProvider::TOKEN_URL && $request['client_secret'] !== '' && $request['code'] === 'auth-code');
    });
});
