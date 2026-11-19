<?php

namespace App\Shared\Notifications\Email;

use App\Models\User;
use App\Shared\Notifications\Sms\SmsSender;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The `sms` channel of catalogue events (e.g. the delivery code): the
 * rendered subject plus the first lines of the body, through the SMS
 * driver (`nawy.sms.driver`). Skipped for users without a phone.
 */
class SmsChannel
{
    public function __construct(private SmsSender $sms) {}

    public function send(object $notifiable, EventEmail $notification): void
    {
        if (! $notifiable instanceof User || blank($notifiable->phone)) {
            return;
        }

        try {
            $rendered = $notification->render($notifiable);

            if ($rendered !== null) {
                $paragraphs = preg_split('/\n{2,}/', $rendered->text) ?: [];
                $this->sms->send((string) $notifiable->phone, mb_substr(trim($paragraphs[2] ?? $rendered->subject), 0, 480));
            }
        } catch (Throwable $exception) {
            Log::warning('Email SMS channel failed', ['event' => $notification->event, 'error' => $exception->getMessage()]);
        }
    }
}
