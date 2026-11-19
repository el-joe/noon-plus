<?php

namespace App\Shared\Notifications\Sms;

/**
 * Test SMS driver: keeps every message in memory.
 */
class ArraySmsSender implements SmsSender
{
    /**
     * @var array<int, array{to: string, message: string}>
     */
    private array $sent = [];

    public function send(string $to, string $message): void
    {
        $this->sent[] = ['to' => $to, 'message' => $message];
    }

    /**
     * @return array<int, array{to: string, message: string}>
     */
    public function sent(): array
    {
        return $this->sent;
    }
}
