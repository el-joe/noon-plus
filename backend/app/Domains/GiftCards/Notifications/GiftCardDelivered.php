<?php

namespace App\Domains\GiftCards\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The gift card email to the recipient — the only place the code appears.
 */
class GiftCardDelivered extends Notification
{
    public function __construct(
        public string $code,
        public string $amount,
        public ?string $senderName,
        public ?string $personalMessage,
        public string $expiresOn,
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
            ->subject(__('You received a Nawy gift card'))
            ->line(__(':sender sent you a Nawy gift card worth :amount.', ['sender' => $this->senderName ?? __('Someone'), 'amount' => $this->amount]));

        if ($this->personalMessage !== null) {
            $mail->line('“'.$this->personalMessage.'”');
        }

        return $mail
            ->line(__('Your code:'))
            ->line($this->code)
            ->line(__('Redeem it in your account under Nawy Credits before :date.', ['date' => $this->expiresOn]));
    }
}
