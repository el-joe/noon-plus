<?php

namespace App\Domains\Delivery\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Credentials email for a new delivery agent account (SRS §15, client
 * requirement 9). The password is only in this email, never stored plain.
 */
class AgentCredentialsNotification extends Notification
{
    use Queueable;

    public function __construct(public string $countryName, public string $password) {}

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
            ->subject(__('Your Nawy delivery account'))
            ->line(__('You were added as a delivery agent in :country.', ['country' => $this->countryName]))
            ->line(__('Sign in to the delivery app with :email and this temporary password: :password', ['email' => $notifiable->email, 'password' => $this->password]))
            ->line(__('Please change it after your first sign-in.'));
    }
}
