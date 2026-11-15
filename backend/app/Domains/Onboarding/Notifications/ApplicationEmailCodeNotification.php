<?php

namespace App\Domains\Onboarding\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The 6-digit code that proves a partner applicant owns the email address
 * (QA-002). When the address already has a Nawy account, entering the code
 * is the owner's consent to add the new workspace to that account.
 */
class ApplicationEmailCodeNotification extends Notification
{
    use Queueable;

    public function __construct(public string $code, public string $applicationNumber, public int $validMinutes, public bool $existingAccount) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('Confirm your email for your Nawy application: :code', ['code' => $this->code]))
            ->line(__('Enter this code to confirm your email for application :number:', ['number' => $this->applicationNumber]))
            ->line($this->code)
            ->line(__('The code is valid for :minutes minutes.', ['minutes' => $this->validMinutes]));

        if ($this->existingAccount) {
            $mail->line(__('This email already has a Nawy account. If the application is approved, the new workspace is added to that account and you keep your current sign-in.'));
        }

        return $mail->line(__('If you did not apply, ignore this email: nothing is created without this code.'));
    }
}
