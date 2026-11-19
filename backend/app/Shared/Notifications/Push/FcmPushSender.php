<?php

namespace App\Shared\Notifications\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Firebase Cloud Messaging HTTP v1 (`POST /v1/projects/{id}/messages:send`).
 * Authenticates with the service account: an RS256-signed JWT is exchanged
 * at Google's token endpoint for an access token, cached until shortly
 * before it expires. A 404 / `UNREGISTERED` answer means the device token
 * is gone (returns false so the caller deletes it); other failures throw.
 */
class FcmPushSender implements PushSender
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /**
     * @param  array{client_email: string, private_key: string, token_uri?: string}  $credentials
     */
    public function __construct(private string $projectId, private array $credentials) {}

    public static function fromConfig(): self
    {
        $projectId = (string) config('nawy.push.fcm.project_id');
        $raw = (string) config('nawy.push.fcm.credentials');
        $json = is_file($raw) ? (string) file_get_contents($raw) : $raw;
        $credentials = json_decode($json, true);

        if ($projectId === '' || ! is_array($credentials) || ! isset($credentials['client_email'], $credentials['private_key'])) {
            throw new RuntimeException('FCM push needs NAWY_FCM_PROJECT_ID and NAWY_FCM_CREDENTIALS (service-account JSON or its path).');
        }

        return new self($projectId, $credentials);
    }

    public function send(PushMessage $message): bool
    {
        $response = Http::withToken($this->accessToken())->timeout(10)
            ->post("https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send", ['message' => array_filter([
                'token' => $message->token,
                'notification' => array_filter(['title' => $message->title, 'body' => $message->body]),
                'data' => $message->data === [] ? null : array_map('strval', $message->data),
            ])]);

        if ($response->successful()) {
            return true;
        }

        if ($response->status() === 404 || str_contains((string) $response->body(), 'UNREGISTERED')) {
            return false;
        }

        throw new RuntimeException('FCM send failed: HTTP '.$response->status());
    }

    private function accessToken(): string
    {
        return Cache::remember('fcm:access-token:'.sha1($this->credentials['client_email']), 3300, function (): string {
            $tokenUri = $this->credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token';
            $now = time();
            $segments = [
                self::base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
                self::base64Url((string) json_encode(['iss' => $this->credentials['client_email'], 'scope' => self::SCOPE, 'aud' => $tokenUri, 'iat' => $now, 'exp' => $now + 3600])),
            ];

            if (! openssl_sign(implode('.', $segments), $signature, $this->credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('FCM: the service-account private key could not sign the token request.');
            }

            $segments[] = self::base64Url($signature);
            $response = Http::asForm()->timeout(10)->post($tokenUri, ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => implode('.', $segments)]);

            return (string) ($response->json('access_token') ?? throw new RuntimeException('FCM: no access token (HTTP '.$response->status().').'));
        });
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
