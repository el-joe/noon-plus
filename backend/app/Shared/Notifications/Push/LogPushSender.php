<?php

namespace App\Shared\Notifications\Push;

use Illuminate\Support\Facades\Log;

/**
 * Development push driver: logs the notification instead of sending it.
 */
class LogPushSender implements PushSender
{
    public function send(PushMessage $message): bool
    {
        Log::info('Push (log driver)', ['platform' => $message->platform, 'token' => substr($message->token, 0, 6).'…', 'title' => $message->title]);

        return true;
    }
}
