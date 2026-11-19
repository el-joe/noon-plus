<?php

namespace App\Domains\Identity\Support\Social;

/**
 * Picks the provider adapter for `google` / `apple` (D9), or the fake one
 * when `nawy.customer_auth.social_driver` is "fake".
 */
class SocialProviders
{
    public function for(string $provider): SocialProvider
    {
        if (! in_array($provider, config('nawy.customer_auth.social_providers'), true)) {
            throw new SocialSignInFailed(SocialSignInFailed::NOT_CONFIGURED, __('This sign-in provider is not supported.'));
        }

        if (config('nawy.customer_auth.social_driver') === 'fake') {
            return new FakeSocialProvider($provider);
        }

        return match ($provider) {
            'google' => app(GoogleProvider::class),
            'apple' => app(AppleProvider::class),
        };
    }
}
