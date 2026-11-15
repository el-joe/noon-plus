<?php

namespace App\Domains\Classifieds\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Renewal reminder sent `classified.renewal_reminder_days` before an ad expires.
 */
class ClassifiedRenewalReminder extends Notification
{
    public function __construct(public string $adNumber, public string $title, public int $daysLeft) {}

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
            ->subject(__('Your ad expires in :days day(s)', ['days' => $this->daysLeft]))
            ->line(__('“:title” (:number) expires in :days day(s).', ['title' => $this->title, 'number' => $this->adNumber, 'days' => $this->daysLeft]))
            ->line(__('Renew or republish it from My ads to keep it live.'));
    }
}
