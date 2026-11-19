<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Exceptions\EmailLoginCodeRejected;
use App\Domains\Identity\Models\EmailLoginCode;
use App\Domains\Identity\Notifications\EmailLoginCodeNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Sends a fresh 6-digit sign-in code to an email address. Any earlier unused
 * code of the same purpose for the address stops working (codes are
 * scoped by purpose: `login`, `email_change`, `panel_2fa`; QA-021).
 */
class RequestEmailLoginCode
{
    public function handle(string $email, string $purpose = 'login'): void
    {
        $email = Str::lower(trim($email));
        $code = $this->issue($email, $purpose);

        Notification::route('mail', $email)->notify(new EmailLoginCodeNotification($code, (int) config('nawy.customer_auth.email_code_ttl_minutes'), $purpose));
    }

    /**
     * Store a fresh code for the address and purpose and return it, without
     * sending anything (the caller mails it, e.g. inside a reset email).
     */
    public function issue(string $email, string $purpose): string
    {
        $email = Str::lower(trim($email));

        $lockedCode = EmailLoginCode::query()
            ->where('email', $email)
            ->where('purpose', $purpose)
            ->where('locked_until', '>', now())
            ->exists();

        if ($lockedCode) {
            throw new EmailLoginCodeRejected(EmailLoginCodeRejected::LOCKED, __('Too many wrong codes. Try again later.'));
        }

        EmailLoginCode::query()
            ->where('email', $email)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $length = (int) config('nawy.customer_auth.email_code_length');
        $validMinutes = (int) config('nawy.customer_auth.email_code_ttl_minutes');
        $code = str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);

        EmailLoginCode::query()->create([
            'email' => $email,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes($validMinutes),
        ]);

        return $code;
    }
}
