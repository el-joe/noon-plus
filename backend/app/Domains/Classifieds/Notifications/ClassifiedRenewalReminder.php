<?php

namespace App\Domains\Classifieds\Notifications;

use App\Shared\Notifications\Email\EmailValue;
use App\Shared\Notifications\Email\EventEmail;
use DateTimeInterface;

/**
 * Renewal reminder (`classified.expiring`) sent `classified.renewal_reminder_days` before an ad expires.
 */
class ClassifiedRenewalReminder extends EventEmail
{
    public function __construct(public string $adNumber, public string $title, public int $daysLeft, ?DateTimeInterface $expiresAt = null, ?string $url = null)
    {
        parent::__construct('classified.expiring', [
            'ad_title' => $title,
            'ad_number' => $adNumber,
            'expires' => EmailValue::date($expiresAt ?? now()->addDays($daysLeft)),
        ], array_filter([
            'url' => $url,
            'entity_type' => 'ClassifiedAd',
            'entity_id' => $adNumber,
            'key' => 'expiring:'.$daysLeft,
        ]));
    }
}
