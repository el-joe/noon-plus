<?php

namespace App\Domains\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailLoginCodeNotification extends Notification
{
    /**
     * `purpose` is `login` (sign-in) or `email_change` (confirm a new address, G-W29).
     */
    public function __construct(public string $code, public int $validMinutes, public string $purpose = 'login') {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->purpose === 'email_change') {
            return (new MailMessage)
                ->subject(__('Confirm your new Nawy email: :code', ['code' => $this->code]))
                ->line(__('Use this code to confirm this address for your Nawy account:'))
                ->line($this->code)
                ->line(__('It expires in :minutes minutes. If you did not ask for this, ignore this email.', ['minutes' => $this->validMinutes]));
        }

        return (new MailMessage)
            ->subject(__('Your Nawy sign-in code: :code', ['code' => $this->code]))
            ->line(__('Use this code to sign in to Nawy:'))
            ->line($this->code)
            ->line(__('It expires in :minutes minutes. Never share it with anyone, including Nawy staff.', ['minutes' => $this->validMinutes]));
    }
}
