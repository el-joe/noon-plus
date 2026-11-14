<?php

namespace App\Domains\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailLoginCodeNotification extends Notification
{
    public function __construct(public string $code, public int $validMinutes) {}

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
            ->subject(__('Your Nawy sign-in code: :code', ['code' => $this->code]))
            ->line(__('Use this code to sign in to Nawy:'))
            ->line($this->code)
            ->line(__('It expires in :minutes minutes. Never share it with anyone, including Nawy staff.', ['minutes' => $this->validMinutes]));
    }
}
