<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Models\SocialAccount;
use App\Domains\Identity\Support\Social\SocialIdentity;
use App\Domains\Identity\Support\Social\SocialSignInFailed;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finds the account behind a verified Google / Apple identity (D9):
 * 1. an identity already linked by provider subject signs in as that account;
 * 2. otherwise the provider's *verified* email links to the account with that
 *    email, or opens a new (verified, passwordless) customer account.
 * An unverified provider email never links or opens an account.
 */
class ResolveSocialUser
{
    public function handle(SocialIdentity $identity): User
    {
        return DB::transaction(function () use ($identity): User {
            $linked = SocialAccount::query()
                ->where('provider', $identity->provider)
                ->where('provider_user_id', $identity->subject)
                ->lockForUpdate()
                ->first();

            if ($linked !== null) {
                $linked->forceFill(['last_used_at' => now(), 'email' => $identity->email ?? $linked->email])->save();

                return $this->usable($linked->user);
            }

            if ($identity->email === null || ! $identity->emailVerified) {
                throw new SocialSignInFailed(SocialSignInFailed::EMAIL_UNVERIFIED, __('Your :provider account has no verified email address. Sign in with an email code instead.', ['provider' => Str::ucfirst($identity->provider)]));
            }

            $email = Str::lower(trim($identity->email));
            $user = User::query()->where('email', $email)->lockForUpdate()->first()
                ?? User::createPasswordless($email, $identity->name ?: Str::before($email, '@'), verified: true);

            $this->usable($user);

            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            // One identity per provider per account: a different Google
            // account with the same email replaces the stale link.
            $user->socialAccounts()->where('provider', $identity->provider)->delete();
            $user->socialAccounts()->create([
                'provider' => $identity->provider,
                'provider_user_id' => $identity->subject,
                'email' => $email,
                'last_used_at' => now(),
            ]);

            return $user;
        });
    }

    private function usable(User $user): User
    {
        if (($user->status ?? 'active') !== 'active') {
            throw new SocialSignInFailed(SocialSignInFailed::ACCOUNT_SUSPENDED, __('This account is suspended. Contact Nawy support.'));
        }

        return $user;
    }
}
