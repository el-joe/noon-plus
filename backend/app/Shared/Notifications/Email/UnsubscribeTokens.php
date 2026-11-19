<?php

namespace App\Shared\Notifications\Email;

/**
 * Signed one-click unsubscribe tokens. The payload carries no address: the
 * address hash, the user id (if any), the topic and the email log row, all
 * signed with the app key so they cannot be forged or changed.
 */
class UnsubscribeTokens
{
    /**
     * @return non-empty-string
     */
    public function make(string $emailHash, string $topic, ?int $userId = null, ?int $logId = null): string
    {
        $payload = $this->encode((string) json_encode(['h' => $emailHash, 't' => $topic, 'u' => $userId, 'l' => $logId]));

        return $payload.'.'.$this->sign($payload);
    }

    /**
     * @return array{hash: string, topic: string, user_id: int|null, log_id: int|null}|null
     */
    public function read(string $token): ?array
    {
        [$payload, $signature] = array_pad(explode('.', $token, 2), 2, '');

        if ($payload === '' || ! hash_equals($this->sign($payload), $signature)) {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);

        if (! is_array($data) || ! is_string($data['h'] ?? null) || ! is_string($data['t'] ?? null)) {
            return null;
        }

        return ['hash' => $data['h'], 'topic' => $data['t'], 'user_id' => isset($data['u']) ? (int) $data['u'] : null, 'log_id' => isset($data['l']) ? (int) $data['l'] : null];
    }

    private function sign(string $payload): string
    {
        return $this->encode(hash_hmac('sha256', 'email-unsubscribe|'.$payload, (string) config('app.key'), true));
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
