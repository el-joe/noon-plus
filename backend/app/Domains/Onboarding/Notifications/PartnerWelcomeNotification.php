<?php

namespace App\Domains\Onboarding\Notifications;

use App\Shared\Notifications\Email\EmailValue;
use App\Shared\Notifications\Email\EventEmail;

/**
 * Sent when an application is approved (catalogue email `partner.welcome`):
 * the panel to sign in to and how — with the password chosen in the wizard
 * (new account), the existing account (the email already had one), or a
 * temporary password when the application carried none. A temporary
 * password only appears in this mail.
 */
class PartnerWelcomeNotification extends EventEmail
{
    public function __construct(
        public string $partnerLabel,
        public string $countryName,
        public string $panelUrl,
        public bool $existingAccount,
        public ?string $temporaryPassword = null,
        public ?string $passwordSetupUrl = null,
        public ?string $email = null,
        ?string $panelName = null,
        ?string $panelKey = null,
    ) {
        $email = (string) $email;
        $blocks = match (true) {
            $temporaryPassword !== null => [
                ['type' => 'note', 'text' => 'Sign in with :email and this temporary password: :password', 'replace' => ['email' => $email, 'password' => $temporaryPassword]],
                ['type' => 'note', 'text' => 'Please change it after your first sign-in.'],
            ],
            $existingAccount => array_values(array_filter([
                ['type' => 'note', 'text' => 'Sign in with your existing Nawy account (:email). The new workspace is now on your account.', 'replace' => ['email' => $email]],
                $passwordSetupUrl === null ? null : ['type' => 'note', 'text' => 'If you usually sign in with an email code and have no password yet, set one here (the link works for 3 days): :link', 'replace' => ['link' => $passwordSetupUrl]],
            ])),
            default => [['type' => 'note', 'text' => 'Sign in with :email and the password you chose when you applied.', 'replace' => ['email' => $email]]],
        };

        parent::__construct('partner.welcome', ['panel' => EmailValue::t($panelName ?? 'Seller Center')], array_filter([
            'url' => $panelUrl,
            'blocks' => $blocks,
            'panel' => $panelKey,
        ]));
    }
}
