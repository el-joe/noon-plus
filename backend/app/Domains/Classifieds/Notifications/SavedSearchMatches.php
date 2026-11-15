<?php

namespace App\Domains\Classifieds\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Saved-search alert: new live ads matching a customer's saved search.
 */
class SavedSearchMatches extends Notification
{
    /**
     * @param  array<int, string>  $titles
     */
    public function __construct(public string $searchName, public int $count, public array $titles) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject(__(':count new ads for “:name”', ['count' => $this->count, 'name' => $this->searchName]));

        foreach (array_slice($this->titles, 0, 5) as $title) {
            $mail->line('• '.$title);
        }

        return $mail;
    }
}
