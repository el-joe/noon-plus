<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Identity\Support\SecurityEmails;
use App\Domains\Identity\Support\TokenScopes;
use App\Models\User;

/**
 * Finishes any customer sign-in (email code, password, Google, Apple; D9):
 * stamps the visit, adds the customer membership for the current market and
 * issues a customer API token. The first sign-in of a newly opened account
 * gets the welcome email (`account.welcome`, once per account).
 */
class SignInCustomer
{
    public function __construct(
        private CountryContext $countryContext,
        private TokenScopes $tokenScopes,
        private SecurityEmails $securityEmails,
    ) {}

    public function handle(User $user, string $deviceName): string
    {
        $firstSignIn = $user->last_login_at === null && ! $user->requires_email && $user->created_at?->gt(now()->subDay());
        $user->forceFill(['last_login_at' => now()])->save();

        if ($firstSignIn) {
            $this->securityEmails->welcome($user);
        }

        if ($this->countryContext->has()) {
            $user->memberships()->firstOrCreate([
                'role' => MembershipRole::Customer,
                'country_id' => $this->countryContext->current()->id,
            ]);
        }

        return $user->createToken($deviceName, $this->tokenScopes->customerAbilities(), $this->tokenScopes->expiresAt(TokenScopes::CUSTOMER))->plainTextToken;
    }

    /**
     * The account part of every sign-in response.
     *
     * `requires_email`: a legacy phone-only account (placeholder email) that must add and verify an email next.
     *
     * @return array{id: int, name: string, email: string, has_password: bool, requires_email: bool}
     */
    public static function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'has_password' => $user->hasChosenPassword(),
            'requires_email' => (bool) $user->requires_email,
        ];
    }
}
