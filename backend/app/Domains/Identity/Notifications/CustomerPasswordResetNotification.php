<?php

namespace App\Domains\Identity\Notifications;

use App\Shared\Notifications\Email\EventEmail;

/**
 * Customer forgot-password email (D9, `auth.password_reset`): a link to the
 * storefront reset page and the same reset as a 6-digit code for the apps.
 */
class CustomerPasswordResetNotification extends EventEmail
{
    public function __construct(public string $url, public string $code, public int $codeMinutes, public int $linkMinutes)
    {
        parent::__construct('auth.password_reset', ['minutes' => (string) $linkMinutes], [
            'url' => $url,
            'blocks' => [['type' => 'note', 'text' => 'In the app, enter this code instead: :code (valid for :minutes minutes).', 'replace' => ['code' => $code, 'minutes' => (string) $codeMinutes]]],
        ]);
    }
}
