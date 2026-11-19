<?php

namespace App\Domains\Finance\Notifications;

use App\Shared\Notifications\Email\Emails;
use App\Shared\Notifications\Email\EventEmail;

/**
 * Vendor subscription mail (FR-FIN-7) through the email catalogue: invoice
 * issued (PDF attached), renewed by auto-charge, auto-charge failed, past
 * due / final notice, expired. Sent in the store owner's language.
 * `subject` / `body` are the in-app texts of the same notice.
 */
class VendorSubscriptionNotice extends EventEmail
{
    /**
     * Catalogue event of each notice kind.
     */
    public const EVENTS = [
        'issued' => 'subscription.invoice',
        'auto_charged' => 'subscription.auto_charged',
        'auto_charge_failed' => 'subscription.auto_charge_failed',
        'past_due' => 'subscription.past_due',
        'final_notice' => 'subscription.past_due',
        'expired' => 'subscription.expired',
    ];

    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $options  see {@see Emails::send()}
     */
    public function __construct(public string $kind, public string $subject, public string $body, public string $link, array $variables = [], array $options = [])
    {
        parent::__construct(self::EVENTS[$kind] ?? 'subscription.past_due', $variables, $options + ['url' => $link, 'panel' => 'partner']);
    }
}
