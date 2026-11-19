<?php

namespace App\Domains\LiveMedia\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\LiveMedia\Enums\StreamStatus;
use App\Domains\LiveMedia\Events\LiveSignal;
use App\Domains\LiveMedia\Exceptions\LiveSignalRefused;
use App\Domains\LiveMedia\Models\LiveStream;
use App\Domains\Settings\SettingsService;
use App\Shared\Broadcasting\Realtime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Native WebRTC live broadcasts (owner decision D18, replaces LiveKit):
 * one-to-many P2P from the host's browser — the admin studio keeps one
 * RTCPeerConnection per viewer, each viewer receives only. No media server.
 *
 * Signalling is relayed by the API over Reverb private channels (no client
 * events), so authorisation stays server-side:
 * - viewer → host: `POST live-streams/{slug}/signal` with the viewer's peer
 *   token → `private-live.{slug}.host` (admins with `live_media.manage`);
 * - host → viewer: the admin studio posts → `private-live.{slug}.peer.{peer}`
 *   (only the holder of that peer's token may subscribe).
 *
 * Viewer slots live in the cache per country + stream: a join beyond
 * `live.webrtc_max_viewers` (country setting, default 20) is refused with
 * `stream_full`; a slot without a heartbeat for `peer_ttl_seconds` frees up.
 */
class WebRtcSignalling
{
    public const VIEWER_TYPES = ['ready', 'answer', 'candidate', 'leave'];

    public const HOST_TYPES = ['offer', 'candidate'];

    public function __construct(
        private CountryContext $countryContext,
        private PeerTokens $tokens,
        private IceServers $ice,
        private SettingsService $settings,
    ) {}

    public static function hostChannel(string $slug): string
    {
        return "live.{$slug}.host";
    }

    public static function peerChannel(string $slug, string $peer): string
    {
        return "live.{$slug}.peer.{$peer}";
    }

    public function maxViewers(): int
    {
        $value = (int) $this->settings->get('live.webrtc_max_viewers', config('nawy.streams.webrtc.max_viewers', 20), $this->countryContext->current());

        return max(1, $value);
    }

    public function isWebRtc(LiveStream $stream): bool
    {
        return ! $stream->isRecorded() && $stream->status === StreamStatus::Live;
    }

    /**
     * Join (or resume a slot with a still valid peer token).
     *
     * @return array<string, mixed>
     */
    public function join(LiveStream $stream, ?string $resumePeer = null, ?string $resumeToken = null): array
    {
        $this->ensureLive($stream);
        $country = $this->country();
        $resumed = $resumePeer !== null && $this->tokens->verify($resumeToken, $country, $stream->slug) === $resumePeer ? $resumePeer : null;

        $peer = Cache::lock($this->key($stream, 'lock'), 5)->block(3, function () use ($stream, $resumed): string {
            $peers = $this->peers($stream);
            $peer = $resumed ?? Str::lower(Str::random(20));

            if (! isset($peers[$peer]) && count($peers) >= $this->maxViewers()) {
                throw new LiveSignalRefused('stream_full', __('This live show is full right now. Try again in a moment.'), 409, ['max_viewers' => $this->maxViewers()]);
            }

            $peers[$peer] = ['joined' => $peers[$peer]['joined'] ?? now()->getTimestamp(), 'seen' => now()->getTimestamp()];
            $this->store($stream, $peers);

            return $peer;
        });

        $issued = $this->tokens->issue($country, $stream->slug, $peer);
        $config = config('nawy.streams.webrtc');
        $reverb = config('broadcasting.connections.reverb');

        return [
            'peer_id' => $peer,
            'peer_token' => $issued['token'],
            'expires_at' => $issued['expires_at']->toIso8601String(),
            'channel' => 'private-'.self::peerChannel($stream->slug, $peer),
            'ice_servers' => $this->ice->for($peer),
            'heartbeat_seconds' => (int) $config['heartbeat_seconds'],
            'max_viewers' => $this->maxViewers(),
            'viewers' => count($this->peers($stream)),
            'host_online' => $this->hostOnline($stream),
            'socket' => [
                'key' => $reverb['key'] ?? null,
                'host' => $config['socket']['host'] ?? null,
                'port' => isset($config['socket']['port']) && $config['socket']['port'] !== '' ? (int) $config['socket']['port'] : null,
                'scheme' => ($config['socket']['scheme'] ?? 'https') === 'http' ? 'http' : 'https',
            ],
        ];
    }

    /**
     * A viewer's message for the host (`ready`, `answer`, `candidate`, `leave`).
     *
     * @param  array<string, mixed>  $data  sdp / candidate
     */
    public function fromViewer(LiveStream $stream, ?string $token, string $type, array $data = []): string
    {
        $peer = $this->peerFromToken($stream, $token);
        $this->ensureLive($stream);

        if ($type === 'leave') {
            $this->forget($stream, $peer);
        } else {
            $this->touch($stream, $peer);
        }

        Realtime::broadcast(new LiveSignal(self::hostChannel($stream->slug), ['peer' => $peer, 'type' => $type] + $this->clean($data)));

        return $peer;
    }

    /**
     * The host studio's message for one viewer (`offer`, `candidate`).
     *
     * @param  array<string, mixed>  $data
     */
    public function fromHost(LiveStream $stream, string $peer, string $type, array $data = []): void
    {
        $this->ensureLive($stream);

        if (! array_key_exists($peer, $this->peers($stream))) {
            throw new LiveSignalRefused('peer_gone', __('That viewer has left.'), 410);
        }

        $this->markHostOnline($stream);
        Realtime::broadcast(new LiveSignal(self::peerChannel($stream->slug, $peer), ['type' => $type] + $this->clean($data)));
    }

    /**
     * Host studio heartbeat; with `$restart` (studio (re)opened) every
     * connected viewer is told to start over (`online`).
     *
     * @return array<string, mixed>
     */
    public function hostHeartbeat(LiveStream $stream, bool $restart): array
    {
        $this->ensureLive($stream);
        $this->markHostOnline($stream);
        $peers = $this->peers($stream);

        if ($restart) {
            foreach (array_keys($peers) as $peer) {
                Realtime::broadcast(new LiveSignal(self::peerChannel($stream->slug, $peer), ['type' => 'online']));
            }
        }

        return [
            'peers' => collect($peers)->map(fn (array $row, string $peer): array => [
                'id' => $peer,
                'joined_at' => CarbonImmutable::createFromTimestamp($row['joined'])->toIso8601String(),
                'seen_at' => CarbonImmutable::createFromTimestamp($row['seen'])->toIso8601String(),
            ])->values()->all(),
            'viewers' => count($peers),
            'max_viewers' => $this->maxViewers(),
        ];
    }

    /**
     * The show ended: tell every viewer and free all slots.
     */
    public function ended(LiveStream $stream): void
    {
        foreach (array_keys($this->peers($stream)) as $peer) {
            Realtime::broadcast(new LiveSignal(self::peerChannel($stream->slug, $peer), ['type' => 'ended']));
        }

        Cache::forget($this->key($stream, 'peers'));
        Cache::forget($this->key($stream, 'host'));
    }

    /**
     * Presence heartbeat with a peer token: keeps the slot and renews the token.
     *
     * @return array{peer_token: string, expires_at: string}|null
     */
    public function renew(LiveStream $stream, ?string $token): ?array
    {
        $peer = $this->tokens->verify($token, $this->country(), $stream->slug);

        if ($peer === null || ! $this->isWebRtc($stream) || ! array_key_exists($peer, $this->peers($stream))) {
            return null;
        }

        $this->touch($stream, $peer);
        $issued = $this->tokens->issue($this->country(), $stream->slug, $peer);

        return ['peer_token' => $issued['token'], 'expires_at' => $issued['expires_at']->toIso8601String()];
    }

    /**
     * Channel auth for `private-live.{slug}.peer.{peer}`: the token must be
     * for this country, stream and peer.
     */
    public function mayJoinPeerChannel(string $slug, string $peer, ?string $token): bool
    {
        $stream = LiveStream::query()->where('slug', $slug)->first();

        return $stream !== null && $this->tokens->verify($token, $this->country(), $slug) === $peer;
    }

    public function hostOnline(LiveStream $stream): bool
    {
        return (int) Cache::get($this->key($stream, 'host'), 0) >= now()->getTimestamp() - (int) config('nawy.streams.webrtc.host_ttl_seconds', 45);
    }

    public function viewers(LiveStream $stream): int
    {
        return count($this->peers($stream));
    }

    /**
     * Active viewer slots (expired ones dropped).
     *
     * @return array<string, array{joined: int, seen: int}>
     */
    public function peers(LiveStream $stream): array
    {
        $cutoff = now()->getTimestamp() - (int) config('nawy.streams.webrtc.peer_ttl_seconds', 90);

        return array_filter((array) Cache::get($this->key($stream, 'peers'), []), fn ($row): bool => is_array($row) && ($row['seen'] ?? 0) >= $cutoff);
    }

    private function peerFromToken(LiveStream $stream, ?string $token): string
    {
        $peer = $this->tokens->verify($token, $this->country(), $stream->slug);

        if ($peer === null) {
            throw new LiveSignalRefused('peer_token_invalid', __('This viewer session expired. Join again.'), 403);
        }

        return $peer;
    }

    private function ensureLive(LiveStream $stream): void
    {
        if (! $this->isWebRtc($stream)) {
            throw new LiveSignalRefused('stream_not_live', __('This show is not live right now.'), 409);
        }
    }

    private function touch(LiveStream $stream, string $peer): void
    {
        Cache::lock($this->key($stream, 'lock'), 5)->block(3, function () use ($stream, $peer): void {
            $peers = $this->peers($stream);

            if (isset($peers[$peer])) {
                $peers[$peer]['seen'] = now()->getTimestamp();
                $this->store($stream, $peers);
            }
        });
    }

    private function forget(LiveStream $stream, string $peer): void
    {
        Cache::lock($this->key($stream, 'lock'), 5)->block(3, function () use ($stream, $peer): void {
            $peers = $this->peers($stream);
            unset($peers[$peer]);
            $this->store($stream, $peers);
        });
    }

    private function markHostOnline(LiveStream $stream): void
    {
        Cache::put($this->key($stream, 'host'), now()->getTimestamp(), now()->addHours(12));
    }

    /**
     * @param  array<string, array{joined: int, seen: int}>  $peers
     */
    private function store(LiveStream $stream, array $peers): void
    {
        Cache::put($this->key($stream, 'peers'), $peers, now()->addHours(12));
    }

    /**
     * Only the SDP / candidate fields travel (no personal data).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clean(array $data): array
    {
        $out = [];

        if (isset($data['sdp']) && is_string($data['sdp'])) {
            $out['sdp'] = $data['sdp'];
        }

        if (isset($data['candidate']) && is_array($data['candidate'])) {
            $out['candidate'] = [
                'candidate' => (string) ($data['candidate']['candidate'] ?? ''),
                'sdpMid' => isset($data['candidate']['sdpMid']) ? (string) $data['candidate']['sdpMid'] : null,
                'sdpMLineIndex' => isset($data['candidate']['sdpMLineIndex']) ? (int) $data['candidate']['sdpMLineIndex'] : null,
            ];
        }

        return $out;
    }

    private function country(): string
    {
        return $this->countryContext->current()->code;
    }

    private function key(LiveStream $stream, string $part): string
    {
        return "live-webrtc:{$this->country()}:{$stream->slug}:{$part}";
    }
}
