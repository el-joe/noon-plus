<?php

namespace App\Domains\Campaigns\Support;

use App\Shared\StateMachine\TransitionRefused;

/**
 * A campaign guard failed; `field` points at the wizard input to fix and
 * `errorCode` is the API code (e.g. `contract_required`).
 */
class CampaignRefused extends TransitionRefused
{
    public string $field = 'campaign';

    public string $errorCode = 'campaign_refused';

    public static function on(string $field, string $message, string $errorCode = 'campaign_refused'): self
    {
        $refused = new self($message);
        $refused->field = $field;
        $refused->errorCode = $errorCode;

        return $refused;
    }
}
