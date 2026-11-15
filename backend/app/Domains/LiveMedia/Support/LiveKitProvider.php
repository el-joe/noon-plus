<?php

namespace App\Domains\LiveMedia\Support;

use App\Domains\LiveMedia\Contracts\LiveMediaProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * LiveKit adapter (ADR 11): the Twirp RoomService over HTTPS with a server
 * token, and participant access tokens — HS256 JWTs signed with the API
 * secret (no SDK; `hash_hmac`). Config: services.livekit.
 */
class LiveKitProvider implements LiveMediaProvider
{
    public function __construct(
        private ?string $url,
        private ?string $apiKey,
        private ?string $apiSecret,
        private ?string $wsUrl = null,
    ) {}

    public function createRoom(string $name, int $maxParticipants = 0): string
    {
        $response = $this->call('CreateRoom', ['name' => $name, 'empty_timeout' => 600, 'max_participants' => $maxParticipants], ['roomCreate' => true]);

        return (string) ($response['sid'] ?? $name);
    }

    public function deleteRoom(string $name): void
    {
        $this->call('DeleteRoom', ['room' => $name], ['roomCreate' => true]);
    }

    public function accessToken(string $room, string $identity, string $displayName, bool $canPublish, int $ttlMinutes): string
    {
        return $this->jwt([
            'sub' => $identity,
            'name' => $displayName,
            'video' => [
                'room' => $room,
                'roomJoin' => true,
                'canPublish' => $canPublish,
                'canSubscribe' => true,
                'canPublishData' => $canPublish,
            ],
        ], $ttlMinutes * 60);
    }

    public function clientUrl(): ?string
    {
        return $this->wsUrl ?? ($this->url === null ? null : preg_replace('#^http#', 'ws', $this->url));
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    public function jwt(array $claims, int $ttlSeconds): string
    {
        if ($this->apiKey === null || $this->apiSecret === null) {
            throw new RuntimeException('LiveKit API key and secret are not configured.');
        }

        $now = time();
        $segments = [
            $this->base64Url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)),
            $this->base64Url(json_encode(['iss' => $this->apiKey, 'nbf' => $now, 'exp' => $now + $ttlSeconds] + $claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        ];
        $segments[] = $this->base64Url(hash_hmac('sha256', implode('.', $segments), $this->apiSecret, true));

        return implode('.', $segments);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $grant
     * @return array<string, mixed>
     */
    private function call(string $method, array $body, array $grant): array
    {
        if ($this->url === null) {
            throw new RuntimeException('LiveKit URL is not configured.');
        }

        $response = Http::withToken($this->jwt(['video' => $grant], 600))
            ->acceptJson()
            ->timeout(10)
            ->post(rtrim($this->url, '/').'/twirp/livekit.RoomService/'.$method, (object) $body);

        if ($response->failed()) {
            throw new RuntimeException("LiveKit {$method} failed with HTTP {$response->status()}.");
        }

        return (array) $response->json();
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
