<?php

namespace App\Domains\Identity\Support\Social;

use Illuminate\Http\Request;

/**
 * One social sign-in provider (D9). Web: `redirectUrl` → provider →
 * `fromCallback`. Apps: the provider's identity token → `fromIdToken`.
 * Every method throws {@see SocialSignInFailed} when the provider's answer
 * cannot be verified.
 */
interface SocialProvider
{
    public function redirectUrl(string $state, string $nonce): string;

    public function fromCallback(Request $request, string $nonce): SocialIdentity;

    public function fromIdToken(string $idToken, ?string $nonce = null): SocialIdentity;
}
