<?php

namespace App\Domains\Delivery\Notifications;

use App\Shared\Notifications\Email\EventEmail;

/**
 * Credentials email for a new delivery agent account (SRS §15, client
 * requirement 9), catalogue email `delivery.agent_credentials`. The
 * password is only in this email, never stored plain.
 */
class AgentCredentialsNotification extends EventEmail
{
    public function __construct(public string $countryName, public string $password, public ?string $company = null)
    {
        parent::__construct('delivery.agent_credentials', ['company' => $company ?? 'Nawy'], [
            'panel' => 'delivery',
            'blocks' => [
                ['type' => 'code', 'value' => $password],
                ['type' => 'note', 'text' => 'Please change it after your first sign-in.'],
            ],
        ]);
    }
}
