<?php

namespace App\Shared\Notifications\Email\Webhooks;

use Illuminate\Http\Request;

/**
 * Postmark webhooks (Bounce, SpamComplaint, Delivery, Open). Postmark has
 * no payload signature; the webhook URL is protected with HTTP basic auth
 * configured in Postmark (`NAWY_MAIL_WEBHOOK_USER` / `_PASSWORD`).
 */
class PostmarkDriver implements EmailWebhookDriver
{
    private const HARD = ['HardBounce', 'BadEmailAddress', 'ManuallyDeactivated', 'SpamNotification', 'Blocked'];

    public function verify(Request $request): bool
    {
        $user = (string) config('nawy.mail.webhooks.postmark.user');
        $password = (string) config('nawy.mail.webhooks.postmark.password');

        return $user !== '' && $password !== ''
            && hash_equals($user, (string) $request->getUser()) && hash_equals($password, (string) $request->getPassword());
    }

    public function parse(Request $request): array
    {
        $type = match ($request->json('RecordType')) {
            'Bounce' => 'bounce',
            'SpamComplaint' => 'complaint',
            'Delivery' => 'delivered',
            'Open' => 'open',
            default => null,
        };

        if ($type === null) {
            return [];
        }

        $logId = $request->json('Metadata.email_log_id');

        return [new EmailFeedback(
            $type,
            $request->json('Email') ?? $request->json('Recipient'),
            $request->json('MessageID'),
            is_numeric($logId) ? (int) $logId : null,
            $type === 'complaint' || in_array($request->json('Type'), self::HARD, true),
            $request->json('Description') ?? $request->json('Details'),
        )];
    }
}
