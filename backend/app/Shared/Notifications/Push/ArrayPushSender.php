<?php

namespace App\Shared\Notifications\Push;

/**
 * Test push driver: keeps every message in memory; tokens listed in
 * `$invalid` answer as unregistered.
 */
class ArrayPushSender implements PushSender
{
    /**
     * @var array<int, PushMessage>
     */
    public array $sent = [];

    /**
     * @var array<int, string>
     */
    public array $invalid = [];

    public function send(PushMessage $message): bool
    {
        if (in_array($message->token, $this->invalid, true)) {
            return false;
        }

        $this->sent[] = $message;

        return true;
    }
}
