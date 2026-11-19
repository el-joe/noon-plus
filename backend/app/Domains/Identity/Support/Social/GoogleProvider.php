<?php

namespace App\Domains\Identity\Support\Social;

use App\Domains\Identity\Support\Jwt\InvalidJwt;
use App\Domains\Identity\Support\Jwt\JwksVerifier;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider as SocialiteGoogle;
use Throwable;

/**
 * Google sign-in (D9): the web OAuth flow runs through Socialite (stateless —
 * the API keeps its own one-time state); app identity tokens are verified
 * against Google's JWKS.
 */
class GoogleProvider implements SocialProvider
{
    public const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    public const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    public function __construct(private JwksVerifier $verifier) {}

    public function redirectUrl(string $state, string $nonce): string
    {
        return $this->socialite()->scopes(['openid', 'email', 'profile'])->with(['state' => $state, 'nonce' => $nonce])->redirect()->getTargetUrl();
    }

    public function fromCallback(Request $request, string $nonce): SocialIdentity
    {
        try {
            $user = $this->socialite()->user();
        } catch (Throwable) {
            throw SocialSignInFailed::invalidToken();
        }

        $raw = (array) $user->getRaw();

        return new SocialIdentity(
            'google',
            (string) $user->getId(),
            $user->getEmail(),
            filter_var($raw['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            $user->getName(),
        );
    }

    public function fromIdToken(string $idToken, ?string $nonce = null): SocialIdentity
    {
        $audiences = array_values(array_filter([config('services.google.client_id'), ...config('services.google.app_client_ids', [])]));

        if ($audiences === []) {
            throw new SocialSignInFailed(SocialSignInFailed::NOT_CONFIGURED, __('Google sign-in is not available right now.'));
        }

        try {
            $claims = $this->verifier->verify($idToken, self::JWKS_URL, self::ISSUERS, $audiences, $nonce);
        } catch (InvalidJwt) {
            throw SocialSignInFailed::invalidToken();
        }

        return new SocialIdentity(
            'google',
            (string) $claims['sub'],
            isset($claims['email']) ? (string) $claims['email'] : null,
            filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            isset($claims['name']) ? (string) $claims['name'] : null,
        );
    }

    private function socialite(): SocialiteGoogle
    {
        if (blank(config('services.google.client_id')) || blank(config('services.google.client_secret'))) {
            throw new SocialSignInFailed(SocialSignInFailed::NOT_CONFIGURED, __('Google sign-in is not available right now.'));
        }

        /** @var SocialiteGoogle $driver */
        $driver = Socialite::driver('google');

        return $driver->stateless()->redirectUrl((string) config('services.google.redirect'));
    }
}
