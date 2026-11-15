<?php

namespace App\Domains\Marketers\Support;

use Illuminate\Support\Facades\Log;

/**
 * WhatsApp sender for invitations (SRS §11.7). The Business API sender
 * (`notifications.whatsapp_sender`) is not provisioned yet, so this adapter
 * logs the message; tests call {@see fake()} and read {@see sent()}.
 */
class WhatsAppMessenger
{
    /**
     * @var array<int, array{to: string, message: string}>|null
     */
    private static ?array $fake = null;

    public static function fake(): void
    {
        self::$fake = [];
    }

    /**
     * @return array<int, array{to: string, message: string}>
     */
    public static function sent(): array
    {
        return self::$fake ?? [];
    }

    public function send(?string $phone, string $message): bool
    {
        if ($phone === null || $phone === '') {
            return false;
        }

        if (self::$fake !== null) {
            self::$fake[] = ['to' => $phone, 'message' => $message];

            return true;
        }

        Log::channel(config('logging.default'))->info('WhatsApp message queued', ['to' => substr($phone, 0, 5).'***']);

        return true;
    }
}
