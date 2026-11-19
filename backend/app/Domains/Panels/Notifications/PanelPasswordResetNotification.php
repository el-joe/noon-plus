<?php

namespace App\Domains\Panels\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Business panel reset link (QC-006).
 */
class PanelPasswordResetNotification extends Notification
{
    public function __construct(public string $url, public string $panelName, public int $minutes) {}

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
            ->subject(__('Reset your Nawy :panel password', ['panel' => $this->panelName]))
            ->line(__('We received a request to reset the password you use for Nawy :panel.', ['panel' => $this->panelName]))
            ->action(__('Reset password'), $this->url)
            ->line(__('The link works for :minutes minutes.', ['minutes' => $this->minutes]))
            ->line(__('If you did not ask for this, ignore this email. Your password stays the same.'));
    }
}
