<?php

namespace App\Shared\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mail for no-payment requests (owner decision WP6): travel booking
 * requests and classified inquiries / viewing requests. `kind` names the
 * event, e.g. `travel_booking.requested` to the agency or
 * `travel_booking.received` to the customer. `actionUrl` adds a button,
 * e.g. the guest's signed booking status link.
 */
class RequestNotification extends Notification
{
    /**
     * @param  array<int, string>  $lines
     */
    public function __construct(public string $kind, public string $subject, public array $lines, public ?string $reference = null, public ?string $actionUrl = null, public ?string $actionText = null) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject);

        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        if ($this->actionUrl !== null) {
            $mail->action($this->actionText ?? __('Open'), $this->actionUrl);
        }

        return $this->reference === null ? $mail : $mail->line(__('Reference: :ref', ['ref' => $this->reference]));
    }
}
