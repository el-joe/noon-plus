<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Exceptions\EmailLoginCodeRejected;
use App\Models\User;
use App\Shared\Notifications\Sms\SmsSender;
use App\Shared\Validation\CountryPhone;
use Illuminate\Support\Facades\DB;

/**
 * The one-time phone code of a legacy phone-only customer (data-migration
 * plan §5.2): migrated accounts without an email carry a placeholder
 * address and `requires_email`. Their first sign-in is by a 6-digit code
 * sent by SMS to the migrated number; the account must then add and verify
 * an email (EnsureAccountEmail), after which email is the login and this
 * path no longer works for it. Codes share the email-code store, limits and
 * lock (`email_login_codes`, key `phone:{E.164}`, purpose `phone_login`).
 */
class PhoneLoginCodes
{
    public const PURPOSE = 'phone_login';

    public function __construct(
        private RequestEmailLoginCode $codes,
        private ConsumeEmailLoginCode $consume,
        private SignInCustomer $signIn,
        private SmsSender $sms,
    ) {}

    /**
     * E.164 form of a number typed in the current market (local or
     * international), or null.
     */
    public static function normalize(string $phone): ?string
    {
        $local = CountryPhone::normalize($phone);

        if ($local !== null) {
            return $local;
        }

        $digits = preg_replace('/[\s\-().]/', '', $phone) ?? '';
        $digits = str_starts_with($digits, '00') ? '+'.substr($digits, 2) : $digits;

        return preg_match('/^\+[1-9]\d{7,14}$/', $digits) === 1 ? $digits : null;
    }

    /**
     * Send a code when the number belongs to a legacy account that still
     * needs an email. Nothing is sent otherwise, and the caller answers the
     * same either way (no account lookup by phone).
     */
    public function send(string $phone, string $locale): bool
    {
        $user = $this->pendingAccount($phone);

        if ($user === null) {
            return false;
        }

        $code = $this->codes->issue(self::key($phone), self::PURPOSE);
        $minutes = (int) config('nawy.customer_auth.email_code_ttl_minutes');
        $message = $locale === 'ar'
            ? "رمز الدخول إلى ناوي: {$code}. صالح لمدة {$minutes} دقائق. لا تشاركه مع أحد."
            : "Your Nawy sign-in code is {$code}. It is valid for {$minutes} minutes. Do not share it.";
        $this->sms->send($phone, $message);

        return true;
    }

    /**
     * Check the code and sign the legacy account in.
     *
     * @return array{user: User, token: string}
     */
    public function verify(string $phone, string $code, string $deviceName): array
    {
        $this->consume->handle(self::key($phone), $code, self::PURPOSE);
        $user = $this->pendingAccount($phone);

        if ($user === null) {
            throw new EmailLoginCodeRejected(EmailLoginCodeRejected::EXPIRED, __('This code has expired. Request a new one.'));
        }

        if (($user->status ?? 'active') !== 'active') {
            throw new EmailLoginCodeRejected(EmailLoginCodeRejected::SUSPENDED, __('This account is suspended. Contact Nawy support.'));
        }

        return DB::transaction(fn (): array => ['user' => $user, 'token' => $this->signIn->handle($user, $deviceName)]);
    }

    private function pendingAccount(string $phone): ?User
    {
        return User::query()->where('phone', $phone)->where('requires_email', true)->orderBy('id')->first();
    }

    private static function key(string $phone): string
    {
        return 'phone:'.$phone;
    }
}
