<?php

namespace App\Domains\Content\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A mail rendered from a notification template (ADR 10) — subject and body
 * already filled for the recipient's country and language.
 */
class TemplatedNotification extends Notification
{
    use Queueable;

    public function __construct(public string $event, public string $subject, public string $body) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->subject)->line($this->body);
    }
}
