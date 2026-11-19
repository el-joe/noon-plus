<?php

namespace App\Shared\Notifications\Email\Webhooks;

use Illuminate\Http\Request;

/**
 * Mailgun webhooks: `signature.signature` = HMAC-SHA256(timestamp + token)
 * with the webhook signing key (`MAILGUN_WEBHOOK_SIGNING_KEY`); stale
 * timestamps (> 15 min) are refused.
 */
class MailgunDriver implements EmailWebhookDriver
{
    public function verify(Request $request): bool
    {
        $key = (string) config('nawy.mail.webhooks.mailgun.signing_key');
        $timestamp = (string) $request->json('signature.timestamp');
        $token = (string) $request->json('signature.token');

        return $key !== '' && $timestamp !== '' && abs(time() - (int) $timestamp) <= 900
            && hash_equals(hash_hmac('sha256', $timestamp.$token, $key), (string) $request->json('signature.signature'));
    }

    public function parse(Request $request): array
    {
        $data = (array) $request->json('event-data', []);
        $severity = $data['severity'] ?? null;
        $type = match ($data['event'] ?? null) {
            'failed' => 'bounce',
            'complained' => 'complaint',
            'delivered' => 'delivered',
            'opened' => 'open',
            default => null,
        };

        if ($type === null) {
            return [];
        }

        $logId = $data['user-variables']['email_log_id'] ?? null;

        return [new EmailFeedback(
            $type,
            $data['recipient'] ?? null,
            $data['message']['headers']['message-id'] ?? null,
            is_numeric($logId) ? (int) $logId : null,
            $type === 'complaint' || ($type === 'bounce' && $severity === 'permanent'),
            $data['delivery-status']['description'] ?? $data['delivery-status']['message'] ?? $data['reason'] ?? null,
        )];
    }
}
