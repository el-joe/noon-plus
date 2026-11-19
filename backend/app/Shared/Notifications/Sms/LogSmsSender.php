<?php

namespace App\Shared\Notifications\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Development SMS driver: writes the message to the log instead of sending
 * it. The number is masked; the text (which may hold a sign-in code) is
 * only logged in full in the `local` environment.
 */
class LogSmsSender implements SmsSender
{
    public function send(string $to, string $message): void
    {
        Log::info('SMS (log driver)', [
            'to' => substr($to, 0, 5).str_repeat('•', max(0, strlen($to) - 8)).substr($to, -3),
            'message' => app()->environment('local') ? $message : preg_replace('/\d{4,}/', '••••••', $message),
        ]);
    }
}
