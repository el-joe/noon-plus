<?php

namespace App\Domains\Newsletter\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The double opt-in email: one link to confirm the subscription.
 */
class ConfirmNewsletterSubscription extends Notification
{
    public function __construct(public string $confirmUrl, public string $countryName) {}

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
            ->subject(__('Confirm your Nawy newsletter subscription'))
            ->line(__('Confirm that you want Nawy :country offers and news by email.', ['country' => $this->countryName]))
            ->action(__('Confirm subscription'), $this->confirmUrl)
            ->line(__('If you did not ask for this, ignore this email and you will not be subscribed.'));
    }
}
