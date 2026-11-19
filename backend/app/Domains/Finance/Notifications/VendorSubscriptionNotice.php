<?php

namespace App\Domains\Finance\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Vendor subscription mail (FR-FIN-7): invoice issued, payment reminder,
 * past due, final notice, expired. Sent in the store owner's language.
 */
class VendorSubscriptionNotice extends Notification
{
    public function __construct(public string $kind, public string $subject, public string $body, public string $link) {}

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
            ->subject($this->subject)
            ->line($this->body)
            ->action(__('Open Subscription'), $this->link);
    }
}
