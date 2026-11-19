<?php

namespace App\Domains\Onboarding\Notifications;

use App\Shared\Notifications\Email\EventEmail;

/**
 * The 6-digit code that proves a partner applicant owns the email address
 * (QA-002), catalogue email `partner.application_email_code`. When the
 * address already has a Nawy account, entering the code is the owner's
 * consent to add the new workspace to that account.
 */
class ApplicationEmailCodeNotification extends EventEmail
{
    public function __construct(public string $code, public string $applicationNumber, public int $validMinutes, public bool $existingAccount, ?string $locale = null, ?string $name = null)
    {
        $blocks = [['type' => 'code', 'value' => $code]];

        if ($existingAccount) {
            $blocks[] = ['type' => 'note', 'text' => 'This email already has a Nawy account. If the application is approved, the new workspace is added to that account and you keep your current sign-in.'];
        }

        $blocks[] = ['type' => 'note', 'text' => 'If you did not apply, ignore this email: nothing is created without this code.'];

        parent::__construct('partner.application_email_code', ['code' => $code, 'minutes' => (string) $validMinutes], array_filter([
            'blocks' => $blocks,
            'locale' => $locale,
            'name' => $name,
            'panel' => 'partner',
        ]));
    }
}
