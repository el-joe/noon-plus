<?php

namespace App\Domains\Panels\Notifications;

use App\Shared\Notifications\Email\EventEmail;

/**
 * Business panel reset link (QC-006, `panel.password_reset`).
 */
class PanelPasswordResetNotification extends EventEmail
{
    public function __construct(public string $url, public string $panelName, public int $minutes, public ?string $panelKey = null)
    {
        parent::__construct('panel.password_reset', ['minutes' => (string) $minutes], array_filter([
            'url' => $url,
            'panel' => $panelKey,
        ]));
    }
}
