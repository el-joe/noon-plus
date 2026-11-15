<?php

namespace App\Domains\Onboarding\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when an application is approved: the panel to sign in to and how —
 * with the password chosen in the wizard (new account), the existing
 * account (the email already had one), or a temporary password when the
 * application carried none. A temporary password only appears in this mail.
 */
class PartnerWelcomeNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $partnerLabel,
        public string $countryName,
        public string $panelUrl,
        public bool $existingAccount,
        public ?string $temporaryPassword = null,
        public ?string $passwordSetupUrl = null,
    ) {}

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
            ->subject(__('Welcome to Nawy — your application is approved'))
            ->line(__('Your :type application in :country is approved.', ['type' => $this->partnerLabel, 'country' => $this->countryName]));

        if ($this->temporaryPassword !== null) {
            $mail->line(__('Sign in with :email and this temporary password: :password', ['email' => $notifiable->email, 'password' => $this->temporaryPassword]))
                ->line(__('Please change it after your first sign-in.'));
        } elseif ($this->existingAccount) {
            $mail->line(__('Sign in with your existing Nawy account (:email). The new workspace is now on your account.', ['email' => $notifiable->email]));

            if ($this->passwordSetupUrl !== null) {
                $mail->line(__('If you usually sign in with an email code and have no password yet, set one here (the link works for 3 days): :link', ['link' => $this->passwordSetupUrl]));
            }
        } else {
            $mail->line(__('Sign in with :email and the password you chose when you applied.', ['email' => $notifiable->email]));
        }

        return $mail->action(__('Open your workspace'), $this->panelUrl);
    }
}
