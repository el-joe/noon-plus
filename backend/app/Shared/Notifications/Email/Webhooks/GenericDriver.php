<?php

namespace App\Shared\Notifications\Email\Webhooks;

use Illuminate\Http\Request;

/**
 * Provider-neutral webhook (any relay or a small adapter): the raw body is
 * signed with HMAC-SHA256 using `NAWY_MAIL_WEBHOOK_SECRET`, hex digest in
 * `X-Nawy-Signature`. Body: `{"events": [{"type": "bounce", "email": "…",
 * "message_id": "…", "log_id": 12, "permanent": true, "reason": "…"}]}`.
 */
class GenericDriver implements EmailWebhookDriver
{
    public function verify(Request $request): bool
    {
        $secret = (string) config('nawy.mail.webhooks.generic.secret');

        return $secret !== '' && hash_equals(hash_hmac('sha256', $request->getContent(), $secret), (string) $request->header('X-Nawy-Signature'));
    }

    public function parse(Request $request): array
    {
        return array_values(array_filter(array_map(fn ($event): ?EmailFeedback => is_array($event) && in_array($event['type'] ?? null, ['delivered', 'bounce', 'complaint', 'open'], true)
            ? new EmailFeedback($event['type'], $event['email'] ?? null, $event['message_id'] ?? null, isset($event['log_id']) ? (int) $event['log_id'] : null, (bool) ($event['permanent'] ?? $event['type'] === 'complaint'), $event['reason'] ?? null)
            : null, (array) $request->json('events', []))));
    }
}
