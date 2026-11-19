<?php

namespace App\Domains\LiveMedia\Support;

use Carbon\CarbonImmutable;

/**
 * D18: signed viewer peer tokens `v1.{payload}.{hmac}` — bound to the
 * country, the stream slug and the peer id, with an expiry
 * (`nawy.streams.webrtc.peer_token_minutes`). They let guests use the
 * signalling endpoints and subscribe to their own peer channel; the HMAC
 * key is derived from APP_KEY and never leaves the server.
 */
class PeerTokens
{
    /**
     * @return array{token: string, expires_at: CarbonImmutable}
     */
    public function issue(string $country, string $slug, string $peer, ?int $minutes = null): array
    {
        $expires = CarbonImmutable::now()->addMinutes($minutes ?? (int) config('nawy.streams.webrtc.peer_token_minutes', 10));
        $payload = $this->encode((string) json_encode(['c' => $country, 's' => $slug, 'p' => $peer, 'e' => $expires->getTimestamp()], JSON_THROW_ON_ERROR));

        return ['token' => 'v1.'.$payload.'.'.$this->sign($payload), 'expires_at' => $expires];
    }

    /**
     * The peer id when the token is valid for this country and stream, else null.
     */
    public function verify(?string $token, string $country, string $slug): ?string
    {
        $claims = $this->claims($token);

        if ($claims === null || $claims['c'] !== $country || $claims['s'] !== $slug) {
            return null;
        }

        return $claims['p'];
    }

    /**
     * @return array{c: string, s: string, p: string, e: int}|null
     */
    public function claims(?string $token): ?array
    {
        if (! is_string($token) || strlen($token) > 512) {
            return null;
        }

        $parts = explode('.', $token);

        if (count($parts) !== 3 || $parts[0] !== 'v1' || ! hash_equals($this->sign($parts[1]), $parts[2])) {
            return null;
        }

        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);

        if (! is_array($claims) || ! isset($claims['c'], $claims['s'], $claims['p'], $claims['e']) || ! is_int($claims['e']) || $claims['e'] < now()->getTimestamp()) {
            return null;
        }

        return ['c' => (string) $claims['c'], 's' => (string) $claims['s'], 'p' => (string) $claims['p'], 'e' => $claims['e']];
    }

    private function sign(string $payload): string
    {
        return $this->encode(hash_hmac('sha256', $payload, hash_hmac('sha256', 'live-webrtc-peer', (string) config('app.key')), true));
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
