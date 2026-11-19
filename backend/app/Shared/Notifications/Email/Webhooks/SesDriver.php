<?php

namespace App\Shared\Notifications\Email\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Amazon SES notifications through SNS. The SNS message signature is
 * verified with the signing certificate (only from `sns.*.amazonaws.com`
 * over https, cached a day) and the topic must be the configured one
 * (`NAWY_MAIL_SES_TOPIC_ARN`). A subscription confirmation is confirmed
 * by visiting its SubscribeURL on the same host.
 */
class SesDriver implements EmailWebhookDriver
{
    public function verify(Request $request): bool
    {
        $message = $this->message($request);
        $topic = (string) config('nawy.mail.webhooks.ses.topic_arn');

        if ($message === null || $topic === '' || ($message['TopicArn'] ?? null) !== $topic) {
            return false;
        }

        $certificate = $this->certificate((string) ($message['SigningCertURL'] ?? ''));

        if ($certificate === null) {
            return false;
        }

        $algorithm = ($message['SignatureVersion'] ?? '1') === '2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;

        return openssl_verify($this->stringToSign($message), (string) base64_decode((string) ($message['Signature'] ?? ''), true), $certificate, $algorithm) === 1;
    }

    public function parse(Request $request): array
    {
        $message = $this->message($request) ?? [];

        if (($message['Type'] ?? null) === 'SubscriptionConfirmation') {
            $url = (string) ($message['SubscribeURL'] ?? '');

            if ($this->isAwsUrl($url)) {
                Http::timeout(10)->get($url);
            }

            return [];
        }

        $notification = json_decode((string) ($message['Message'] ?? ''), true);

        if (! is_array($notification)) {
            return [];
        }

        $type = $notification['notificationType'] ?? $notification['eventType'] ?? null;
        $messageId = $notification['mail']['messageId'] ?? null;
        $tags = $notification['mail']['tags']['email_log_id'][0] ?? null;
        $logId = is_numeric($tags) ? (int) $tags : null;

        return match ($type) {
            'Bounce' => array_map(fn (array $recipient): EmailFeedback => new EmailFeedback('bounce', $recipient['emailAddress'] ?? null, $messageId, $logId,
                ($notification['bounce']['bounceType'] ?? null) === 'Permanent', $recipient['diagnosticCode'] ?? ($notification['bounce']['bounceSubType'] ?? null)), $notification['bounce']['bouncedRecipients'] ?? []),
            'Complaint' => array_map(fn (array $recipient): EmailFeedback => new EmailFeedback('complaint', $recipient['emailAddress'] ?? null, $messageId, $logId, true,
                $notification['complaint']['complaintFeedbackType'] ?? null), $notification['complaint']['complainedRecipients'] ?? []),
            'Delivery' => [new EmailFeedback('delivered', null, $messageId, $logId)],
            'Open' => [new EmailFeedback('open', null, $messageId, $logId)],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function message(Request $request): ?array
    {
        $message = json_decode($request->getContent(), true);

        return is_array($message) ? $message : null;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function stringToSign(array $message): string
    {
        $keys = ($message['Type'] ?? null) === 'Notification'
            ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
            : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
        $string = '';

        foreach ($keys as $key) {
            if (isset($message[$key])) {
                $string .= $key."\n".$message[$key]."\n";
            }
        }

        return $string;
    }

    private function certificate(string $url): ?string
    {
        if (! $this->isAwsUrl($url) || ! str_ends_with((string) parse_url($url, PHP_URL_PATH), '.pem')) {
            return null;
        }

        try {
            return Cache::remember('ses-sns-cert:'.sha1($url), now()->addDay(), fn (): string => Http::timeout(10)->get($url)->throw()->body());
        } catch (Throwable $exception) {
            Log::warning('SES signing certificate could not be fetched', ['error' => $exception->getMessage()]);

            return null;
        }
    }

    private function isAwsUrl(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return parse_url($url, PHP_URL_SCHEME) === 'https' && preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/', $host) === 1;
    }
}
