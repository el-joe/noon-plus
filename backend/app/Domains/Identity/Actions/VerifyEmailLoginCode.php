<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Identity\Support\TokenScopes;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Checks a customer sign-in code and signs the customer in, creating the
 * account on first use (MASTER_PLAN ADR 5).
 */
class VerifyEmailLoginCode
{
    public function __construct(
        private CountryContext $countryContext,
        private ConsumeEmailLoginCode $consumeCode,
    ) {}

    /**
     * @return array{user: User, token: string}
     */
    public function handle(string $email, string $code, string $deviceName): array
    {
        $email = Str::lower(trim($email));
        $this->consumeCode->handle($email, $code);

        return DB::transaction(function () use ($email, $deviceName): array {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                ['name' => Str::before($email, '@'), 'password' => Str::random(40)],
            );
            $user->forceFill([
                'email_verified_at' => $user->email_verified_at ?? now(),
                'last_login_at' => now(),
            ])->save();

            if ($this->countryContext->has()) {
                $user->memberships()->firstOrCreate([
                    'role' => MembershipRole::Customer,
                    'country_id' => $this->countryContext->current()->id,
                ]);
            }

            return ['user' => $user, 'token' => $user->createToken($deviceName, app(TokenScopes::class)->customerAbilities(), app(TokenScopes::class)->expiresAt(TokenScopes::CUSTOMER))->plainTextToken];
        });
    }
}
