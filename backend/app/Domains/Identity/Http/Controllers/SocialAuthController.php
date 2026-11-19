<?php

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Actions\RecordLoginEvent;
use App\Domains\Identity\Actions\ResolveSocialUser;
use App\Domains\Identity\Actions\SignInCustomer;
use App\Domains\Identity\Enums\LoginMethod;
use App\Domains\Identity\Enums\LoginOutcome;
use App\Domains\Identity\Support\Social\SocialIdentity;
use App\Domains\Identity\Support\Social\SocialProviders;
use App\Domains\Identity\Support\Social\SocialSignInFailed;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Google and Apple sign-in for customers (D9).
 *
 * Web: `redirect` (per market) → provider → `callback` (one URL per
 * provider, registered with it) → storefront `/{market}/auth/social/complete
 * ?ticket=…` → `exchange` turns the one-time ticket into an API token, so a
 * token never travels in a URL.
 * Apps: `token` verifies the provider's identity token server-side. Apps
 * without the native SDKs use the web flow with a PKCE `code_challenge`
 * (S256): the callback then returns to the app's custom scheme
 * (`customer_auth.app_redirect_uri`), and `exchange` needs the matching
 * `code_verifier`, so a ticket caught by another app is useless.
 * Accounts link by verified email ({@see ResolveSocialUser}).
 */
class SocialAuthController extends Controller
{
    public function __construct(
        private SocialProviders $providers,
        private ResolveSocialUser $resolveUser,
        private SignInCustomer $signIn,
        private RecordLoginEvent $recordLoginEvent,
    ) {}

    public function redirect(Request $request, string $provider): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'return_to' => ['nullable', 'string', 'max:500', 'regex:#^/(?!/)#'],
            'code_challenge' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/'],
        ]);
        $state = Str::random(40);
        $nonce = Str::random(32);

        try {
            $url = $this->providers->for($provider)->redirectUrl($state, $nonce);
        } catch (SocialSignInFailed $failed) {
            return ApiResponse::error($failed->reason, $failed->getMessage(), $failed->status());
        }

        Cache::put('social-state:'.$state, [
            'provider' => $provider,
            'market' => $request->attributes->get('market'),
            'nonce' => $nonce,
            'return_to' => $data['return_to'] ?? null,
            'code_challenge' => $data['code_challenge'] ?? null,
        ], now()->addMinutes((int) config('nawy.customer_auth.social_state_ttl_minutes')));

        return $request->wantsJson()
            ? ApiResponse::success(['url' => $url])
            : redirect()->away($url);
    }

    /**
     * The provider's redirect back (Google: GET; Apple: form POST). Always
     * answers with a redirect to the storefront.
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $state = Cache::pull('social-state:'.(string) $request->input('state'));

        if (! is_array($state) || $state['provider'] !== $provider) {
            return redirect()->away($this->storefront(null, 'login', ['social_error' => SocialSignInFailed::INVALID_STATE]));
        }

        try {
            $user = $this->resolveUser->handle($this->providers->for($provider)->fromCallback($request, $state['nonce']));
        } catch (SocialSignInFailed $failed) {
            $this->recordLoginEvent->handle($request, 'api', LoginOutcome::Rejected, method: LoginMethod::from($provider));

            return redirect()->away(isset($state['code_challenge'])
                ? $this->app(['social_error' => $failed->reason])
                : $this->storefront($state['market'], 'login', ['social_error' => $failed->reason]));
        }

        $ticket = Str::random(48);
        Cache::put('social-ticket:'.$ticket, [
            'user_id' => $user->id,
            'market' => $state['market'],
            'provider' => $provider,
            'code_challenge' => $state['code_challenge'] ?? null,
        ], now()->addSeconds((int) config('nawy.customer_auth.social_ticket_ttl_seconds')));

        if (isset($state['code_challenge'])) {
            return redirect()->away($this->app(['ticket' => $ticket, 'market' => $state['market']]));
        }

        return redirect()->away($this->storefront($state['market'], 'auth/social/complete', array_filter([
            'ticket' => $ticket,
            'return_to' => $state['return_to'],
        ])));
    }

    public function exchange(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ticket' => ['required', 'string', 'max:100'],
            'device_name' => ['required', 'string', 'max:100'],
            'code_verifier' => ['nullable', 'string', 'min:43', 'max:128'],
        ]);
        $key = 'social-ticket:'.$data['ticket'];
        $peek = Cache::get($key);

        // RT-008: a ticket tried in the wrong market is refused without being spent.
        if (! is_array($peek) || $peek['market'] !== $request->attributes->get('market')) {
            return ApiResponse::error('social_ticket_invalid', __('This sign-in has expired. Try again.'), 422);
        }

        $ticket = Cache::pull($key);

        if (! is_array($ticket) || $ticket['market'] !== $request->attributes->get('market')
            || ! $this->verifierMatches($ticket['code_challenge'] ?? null, $data['code_verifier'] ?? null)
            || ($user = User::query()->find($ticket['user_id'])) === null) {
            return ApiResponse::error('social_ticket_invalid', __('This sign-in has expired. Try again.'), 422);
        }

        return $this->signedIn($request, $user, LoginMethod::from($ticket['provider']), $data['device_name']);
    }

    /**
     * App sign-in: the identity token from the Google / Apple SDK.
     */
    public function token(Request $request, string $provider): JsonResponse
    {
        $data = $request->validate([
            'id_token' => ['required', 'string', 'max:8192'],
            'device_name' => ['required', 'string', 'max:100'],
            'nonce' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $identity = $this->providers->for($provider)->fromIdToken($data['id_token'], $data['nonce'] ?? null);

            if ($identity->name === null && filled($data['name'] ?? null)) {
                $identity = new SocialIdentity($identity->provider, $identity->subject, $identity->email, $identity->emailVerified, trim($data['name']));
            }

            $user = $this->resolveUser->handle($identity);
        } catch (SocialSignInFailed $failed) {
            if ($failed->reason !== SocialSignInFailed::NOT_CONFIGURED) {
                $this->recordLoginEvent->handle($request, 'api', LoginOutcome::Rejected, method: LoginMethod::from($provider));
            }

            return ApiResponse::error($failed->reason, $failed->getMessage(), $failed->status());
        }

        return $this->signedIn($request, $user, LoginMethod::from($provider), $data['device_name']);
    }

    /**
     * Remove a linked provider; the email code (and any password) still work.
     */
    public function unlink(Request $request, string $provider): JsonResponse
    {
        $deleted = $request->user()->socialAccounts()->where('provider', $provider)->delete();

        if ($deleted === 0) {
            return ApiResponse::error('social_account_not_linked', __('That sign-in provider is not linked to your account.'), 404);
        }

        return ApiResponse::success(['unlinked' => $provider]);
    }

    private function signedIn(Request $request, User $user, LoginMethod $method, string $deviceName): JsonResponse
    {
        $token = $this->signIn->handle($user, $deviceName);
        $this->recordLoginEvent->handle($request, 'api', LoginOutcome::Succeeded, $user, method: $method);

        return ApiResponse::success(['token' => $token, 'user' => SignInCustomer::present($user)]);
    }

    /**
     * PKCE S256: base64url(sha256(verifier)) without padding.
     */
    private function verifierMatches(?string $challenge, ?string $verifier): bool
    {
        if ($challenge === null) {
            return true;
        }

        if ($verifier === null) {
            return false;
        }

        return hash_equals($challenge, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='));
    }

    /**
     * The app's custom-scheme return (`nawy://auth/social/complete?…`).
     *
     * @param  array<string, string>  $query
     */
    private function app(array $query): string
    {
        return config('nawy.customer_auth.app_redirect_uri').'?'.http_build_query($query);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function storefront(?string $market, string $path, array $query): string
    {
        $base = rtrim((string) config('nawy.payments.storefront_url'), '/');

        return $base.($market !== null ? '/'.$market : '').'/'.$path.'?'.http_build_query($query);
    }
}
