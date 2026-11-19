<?php

namespace App\Domains\Identity\Notifications;

use App\Shared\Notifications\Email\EmailValue;
use App\Shared\Notifications\Email\EventEmail;

/**
 * The emailed 6-digit code (`auth.email_code`): sign-in, password reset,
 * a new email address or the panel second step. Sent through the email
 * engine (log, suppression list, branded layout) with the code in its own block.
 */
class EmailLoginCodeNotification extends EventEmail
{
    /**
     * `purpose` is `login` (sign-in), `password_reset`, `email_change` (confirm a new address, G-W29) or `panel_2fa`.
     */
    public function __construct(public string $code, public int $validMinutes, public string $purpose = 'login')
    {
        parent::__construct('auth.email_code', [
            'code' => $code,
            'purpose' => EmailValue::t(match ($purpose) {
                'email_change' => 'confirm your new email address',
                'password_reset' => 'reset your password',
                'panel_2fa' => 'finish signing in',
                default => 'sign in to Nawy',
            }),
            'minutes' => (string) $validMinutes,
        ], [
            'blocks' => [['type' => 'code', 'value' => $code]],
            'locale' => app()->getLocale(),
        ]);
    }
}
