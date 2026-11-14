<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Exceptions\EmailLoginCodeRejected;
use App\Domains\Identity\Models\EmailLoginCode;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Checks the latest emailed code for an address and marks it used.
 * Five wrong codes lock the address for 15 minutes.
 */
class ConsumeEmailLoginCode
{
    public function handle(string $email, string $code): void
    {
        $email = Str::lower(trim($email));

        $loginCode = EmailLoginCode::query()
            ->where('email', $email)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if ($loginCode?->locked_until?->isFuture()) {
            throw new EmailLoginCodeRejected(EmailLoginCodeRejected::LOCKED, __('Too many wrong codes. Try again later.'));
        }

        if ($loginCode === null || $loginCode->expires_at->isPast()) {
            throw new EmailLoginCodeRejected(EmailLoginCodeRejected::EXPIRED, __('This code has expired. Request a new one.'));
        }

        if (! Hash::check($code, $loginCode->code_hash)) {
            $attempts = $loginCode->attempts + 1;
            $loginCode->update([
                'attempts' => $attempts,
                'locked_until' => $attempts >= (int) config('nawy.customer_auth.email_code_max_attempts')
                    ? now()->addMinutes((int) config('nawy.customer_auth.email_code_lock_minutes'))
                    : null,
            ]);

            throw new EmailLoginCodeRejected(EmailLoginCodeRejected::INVALID, __('That code is not correct.'));
        }

        $loginCode->update(['consumed_at' => now()]);
    }
}
