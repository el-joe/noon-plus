<?php

namespace App\Domains\Newsletter\Notifications;

use App\Shared\Notifications\Email\EventEmail;

/**
 * The double opt-in email (`newsletter.confirm`): one link to confirm the subscription.
 */
class ConfirmNewsletterSubscription extends EventEmail
{
    public function __construct(public string $confirmUrl, public string $countryName)
    {
        parent::__construct('newsletter.confirm', [], [
            'url' => $confirmUrl,
            'locale' => app()->getLocale(),
        ]);
    }
}
