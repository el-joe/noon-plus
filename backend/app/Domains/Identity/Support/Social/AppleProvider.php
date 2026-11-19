<?php

namespace App\Domains\Identity\Support\Social;

use App\Domains\Identity\Support\Jwt\Es256Signer;
use App\Domains\Identity\Support\Jwt\InvalidJwt;
use App\Domains\Identity\Support\Jwt\JwksVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Sign in with Apple (D9), without a community Socialite provider: the web
 * flow posts back (`response_mode=form_post`), the code is exchanged with an
 * ES256 client-secret JWT, and every identity token is verified against
 * Apple's JWKS with openssl. Apple sends the person's name only on the first
 * web sign-in, in the `user` form field.
 */
class AppleProvider implements SocialProvider
{
    public const AUTHORIZE_URL = 'https://appleid.apple.com/auth/authorize';

    public const TOKEN_URL = 'https://appleid.apple.com/auth/token';

    public const JWKS_URL = 'https://appleid.apple.com/auth/keys';

    public const ISSUER = 'https://appleid.apple.com';

    public function __construct(private JwksVerifier $verifier, private Es256Signer $signer) {}

    public function redirectUrl(string $state, string $nonce): string
    {
        $this->requireWebConfig();

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'response_type' => 'code',
            'response_mode' => 'form_post',
            'client_id' => config('services.apple.client_id'),
            'redirect_uri' => config('services.apple.redirect'),
            'scope' => 'name email',
            'state' => $state,
            'nonce' => $nonce,
        ]);
    }

    public function fromCallback(Request $request, string $nonce): SocialIdentity
    {
        $this->requireWebConfig();
        $code = $request->input('code');

        if (! is_string($code) || $code === '') {
            throw SocialSignInFailed::invalidToken();
        }

        $response = Http::asForm()->timeout(15)->acceptJson()->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('services.apple.redirect'),
            'client_id' => config('services.apple.client_id'),
            'client_secret' => $this->clientSecret(),
        ]);

        if (! $response->successful() || ! is_string($response->json('id_token'))) {
            throw SocialSignInFailed::invalidToken();
        }

        $identity = $this->verify($response->json('id_token'), [(string) config('services.apple.client_id')], $nonce);
        $user = json_decode((string) $request->input('user', ''), true);
        $name = is_array($user) ? trim(($user['name']['firstName'] ?? '').' '.($user['name']['lastName'] ?? '')) : '';

        return new SocialIdentity($identity->provider, $identity->subject, $identity->email, $identity->emailVerified, $name !== '' ? $name : null);
    }

    public function fromIdToken(string $idToken, ?string $nonce = null): SocialIdentity
    {
        $audiences = array_values(array_filter([config('services.apple.client_id'), ...config('services.apple.app_ids', [])]));

        if ($audiences === []) {
            throw new SocialSignInFailed(SocialSignInFailed::NOT_CONFIGURED, __('Apple sign-in is not available right now.'));
        }

        return $this->verify($idToken, $audiences, $nonce);
    }

    /**
     * The client secret Apple expects: an ES256 JWT signed with the team's
     * .p8 key, valid for an hour (Apple allows up to six months).
     */
    public function clientSecret(): string
    {
        $key = config('services.apple.private_key');

        if (blank($key) && filled($path = config('services.apple.private_key_path')) && is_readable($path)) {
            $key = file_get_contents($path);
        }

        if (blank($key) || blank(config('services.apple.team_id')) || blank(config('services.apple.key_id'))) {
            throw new SocialSignInFailed(SocialSignInFailed::NOT_CONFIGURED, __('Apple sign-in is not available right now.'));
        }

        try {
            return $this->signer->sign(['kid' => config('services.apple.key_id')], [
                'iss' => config('services.apple.team_id'),
                'iat' => time(),
                'exp' => time() + 3600,
                'aud' => self::ISSUER,
                'sub' => config('services.apple.client_id'),
            ], str_replace('\n', "\n", (string) $key));
        } catch (InvalidJwt) {
            throw new SocialSignInFailed(SocialSignInFailed::NOT_CONFIGURED, __('Apple sign-in is not available right now.'));
        }
    }

    /**
     * @param  list<string>  $audiences
     */
    private function verify(string $idToken, array $audiences, ?string $nonce): SocialIdentity
    {
        try {
            $claims = $this->verifier->verify($idToken, self::JWKS_URL, [self::ISSUER], $audiences, $nonce);
        } catch (InvalidJwt) {
            throw SocialSignInFailed::invalidToken();
        }

        return new SocialIdentity(
            'apple',
            (string) $claims['sub'],
            isset($claims['email']) ? (string) $claims['email'] : null,
            filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }

    private function requireWebConfig(): void
    {
        if (blank(config('services.apple.client_id'))) {
            throw new SocialSignInFailed(SocialSignInFailed::NOT_CONFIGURED, __('Apple sign-in is not available right now.'));
        }
    }
}
