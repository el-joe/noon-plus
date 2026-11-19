<?php

namespace App\Domains\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Customer forgot-password email (D9): a link to the storefront reset page
 * and the same reset as a 6-digit code for the apps.
 */
class CustomerPasswordResetNotification extends Notification
{
    public function __construct(public string $url, public string $code, public int $codeMinutes, public int $linkMinutes) {}

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
            ->subject(__('Reset your Nawy password'))
            ->line(__('We received a request to reset the password for your Nawy account.'))
            ->action(__('Reset password'), $this->url)
            ->line(__('The link works for :minutes minutes.', ['minutes' => $this->linkMinutes]))
            ->line(__('In the app, enter this code instead: :code (valid for :minutes minutes).', ['code' => $this->code, 'minutes' => $this->codeMinutes]))
            ->line(__('If you did not ask for this, ignore this email. Your password stays the same.'));
    }
}
