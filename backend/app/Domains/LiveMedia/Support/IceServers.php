<?php

namespace App\Domains\LiveMedia\Support;

/**
 * D18: the ICE servers handed to the host studio and each viewer — STUN
 * (`NAWY_WEBRTC_STUN_URLS`) plus an optional TURN relay, either with
 * static credentials or time-limited coturn REST credentials
 * (`use-auth-secret`: username `{expiry}:{label}`, credential
 * base64(HMAC-SHA1(secret, username))). See docs/deploy/webrtc.md.
 */
class IceServers
{
    /**
     * @return array<int, array{urls: array<int, string>, username?: string, credential?: string}>
     */
    public function for(string $label): array
    {
        $config = config('nawy.streams.webrtc');
        $servers = [];
        $stun = $this->urls($config['stun_urls'] ?? null);

        if ($stun !== []) {
            $servers[] = ['urls' => $stun];
        }

        $turn = $this->urls($config['turn_url'] ?? null);

        if ($turn !== []) {
            $credentials = $this->turnCredentials($label, $config);

            if ($credentials !== null) {
                $servers[] = ['urls' => $turn] + $credentials;
            }
        }

        return $servers;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{username: string, credential: string}|null
     */
    public function turnCredentials(string $label, array $config): ?array
    {
        $secret = (string) ($config['turn_secret'] ?? '');

        if ($secret !== '') {
            $username = (now()->getTimestamp() + max(60, (int) ($config['turn_ttl_seconds'] ?? 3600))).':'.preg_replace('/[^A-Za-z0-9_-]/', '', $label);

            return ['username' => $username, 'credential' => base64_encode(hash_hmac('sha1', $username, $secret, true))];
        }

        $username = (string) ($config['turn_username'] ?? '');
        $credential = (string) ($config['turn_credential'] ?? '');

        return $username !== '' && $credential !== '' ? ['username' => $username, 'credential' => $credential] : null;
    }

    /**
     * @return array<int, string>
     */
    private function urls(mixed $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn (string $url): bool => $url !== ''));
    }
}
