<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Exceptions\EmailLoginCodeRejected;
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
        private ConsumeEmailLoginCode $consumeCode,
        private SignInCustomer $signIn,
    ) {}

    /**
     * @return array{user: User, token: string}
     */
    public function handle(string $email, string $code, string $deviceName): array
    {
        $email = Str::lower(trim($email));
        $this->consumeCode->handle($email, $code);

        return DB::transaction(function () use ($email, $deviceName): array {
            $user = User::query()->where('email', $email)->first() ?? User::createPasswordless($email, Str::before($email, '@'));

            if (($user->status ?? 'active') !== 'active') {
                throw new EmailLoginCodeRejected(EmailLoginCodeRejected::SUSPENDED, __('This account is suspended. Contact Nawy support.'));
            }

            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            return ['user' => $user, 'token' => $this->signIn->handle($user, $deviceName)];
        });
    }
}
