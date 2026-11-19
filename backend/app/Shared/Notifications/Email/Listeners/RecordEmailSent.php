<?php

namespace App\Shared\Notifications\Email\Listeners;

use App\Shared\Notifications\Email\EventEmail;
use App\Shared\Notifications\Email\Models\EmailLog;
use Illuminate\Mail\SentMessage;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Marks the email log row sent with the provider's message id (SES keeps
 * its own id in `X-SES-Message-ID`; other transports use the Message-ID).
 */
class RecordEmailSent
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'mail' || ! $event->notification instanceof EventEmail || $event->notification->logId === null) {
            return;
        }

        $messageId = null;

        if ($event->response instanceof SentMessage) {
            $headers = $event->response->getOriginalMessage()->getHeaders();
            $messageId = $headers->has('X-SES-Message-ID') ? $headers->get('X-SES-Message-ID')->getBodyAsString() : $event->response->getMessageId();
        }

        EmailLog::query()->whereKey($event->notification->logId)->update([
            'status' => 'sent',
            'sent_at' => now(),
            'failure_reason' => null,
            'provider_message_id' => $messageId === null ? null : mb_substr(trim($messageId, '<> '), 0, 255),
        ]);
    }
}
