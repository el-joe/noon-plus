<?php

namespace App\Domains\Panels\Notifications;

use App\Shared\Notifications\Email\EmailValue;
use App\Shared\Notifications\Email\EventEmail;

/**
 * Invitation to join a team on Nawy (QA-003, QC-020), catalogue email
 * `team.invitation`; the link is the only way to accept it.
 */
class TeamInvitationNotification extends EventEmail
{
    public function __construct(public string $teamName, public string $roleLabel, public string $acceptUrl, public int $validDays, public ?string $platformPanel = null, ?string $inviterName = null, ?string $inviteeName = null, ?string $panelKey = null)
    {
        parent::__construct('team.invitation', [
            'inviter' => $inviterName ?? 'Nawy',
            'team' => match ($platformPanel) {
                null => EmailValue::t(':team on Nawy', ['team' => $teamName]),
                'delivery' => EmailValue::t('the Nawy delivery team'),
                default => EmailValue::t('the Nawy admin team'),
            },
            'role' => $roleLabel,
            'expires' => EmailValue::date(now()->addDays($validDays)),
        ], array_filter([
            'url' => $acceptUrl,
            'name' => $inviteeName,
            'locale' => app()->getLocale(),
            'panel' => $panelKey ?? $platformPanel,
        ]));
    }

    /**
     * Nawy's own teams are named by panel ("join the Nawy admin team"), not
     * "join Nawy on Nawy" (browser test BT-ADM-009).
     */
    public function subject(): string
    {
        return match ($this->platformPanel) {
            null => __('You are invited to join :team on Nawy', ['team' => $this->teamName]),
            'delivery' => __('You are invited to join the Nawy delivery team'),
            default => __('You are invited to join the Nawy admin team'),
        };
    }
}
