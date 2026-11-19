<?php

namespace App\Domains\Identity\Support\Social;

use App\Domains\Identity\Support\Jwt\Base64Url;
use Illuminate\Http\Request;

/**
 * Deterministic stand-in for Google and Apple (tests, local without keys;
 * `nawy.customer_auth.social_driver = fake`). A token — or a web callback
 * `code` — is `fake.` + base64url(JSON {sub, email, email_verified, name,
 * nonce}); anything else is refused, like a bad signature would be.
 */
class FakeSocialProvider implements SocialProvider
{
    public function __construct(private string $provider) {}

    /**
     * @param  array{sub: string, email?: ?string, email_verified?: bool, name?: ?string, nonce?: ?string}  $claims
     */
    public static function token(array $claims): string
    {
        return 'fake.'.Base64Url::encode((string) json_encode($claims));
    }

    public function redirectUrl(string $state, string $nonce): string
    {
        return config("services.{$this->provider}.redirect").'?'.http_build_query(['state' => $state, 'fake' => 1]);
    }

    public function fromCallback(Request $request, string $nonce): SocialIdentity
    {
        return $this->fromIdToken((string) $request->input('code'), $nonce);
    }

    public function fromIdToken(string $idToken, ?string $nonce = null): SocialIdentity
    {
        if (! str_starts_with($idToken, 'fake.')) {
            throw SocialSignInFailed::invalidToken();
        }

        $claims = json_decode(Base64Url::decode(substr($idToken, 5)), true);

        if (! is_array($claims) || ! is_string($claims['sub'] ?? null) || ($nonce !== null && ($claims['nonce'] ?? $nonce) !== $nonce)) {
            throw SocialSignInFailed::invalidToken();
        }

        return new SocialIdentity(
            $this->provider,
            $claims['sub'],
            $claims['email'] ?? null,
            (bool) ($claims['email_verified'] ?? false),
            $claims['name'] ?? null,
        );
    }
}
