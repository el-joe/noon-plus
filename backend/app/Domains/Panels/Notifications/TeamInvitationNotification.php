<?php

namespace App\Domains\Panels\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitation to join a team on Nawy (QA-003, QC-020); the link is the only
 * way to accept it.
 */
class TeamInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(public string $teamName, public string $roleLabel, public string $acceptUrl, public int $validDays, public ?string $platformPanel = null) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject())
            ->line(__(':team invited you to join as :role.', ['team' => $this->teamName, 'role' => $this->roleLabel]))
            ->line(__('Nothing changes on your account unless you accept. The invitation is valid for :days days.', ['days' => $this->validDays]))
            ->action(__('Review the invitation'), $this->acceptUrl)
            ->line(__('If you were not expecting this, ignore this email.'));
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
