<?php

use App\Domains\LiveMedia\Events\LiveSignal;
use App\Domains\LiveMedia\Events\LiveStreamUpdated;
use App\Domains\LiveMedia\Support\IceServers;
use App\Domains\LiveMedia\Support\LiveStreams;
use App\Domains\LiveMedia\Support\PeerTokens;
use App\Domains\Settings\SettingsService;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/*
| Owner decision D18: live broadcasts are native WebRTC (one-to-many P2P from
| the admin studio) with signalling relayed by the API over Reverb private
| channels; LiveKit is gone.
*/

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
    Cache::flush();
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => '1',
    ]);
    app(BroadcastManager::class)->purge();
    require base_path('routes/channels.php');
    Event::fake([LiveSignal::class, LiveStreamUpdated::class]);
    $this->streams = app(LiveStreams::class);
    $this->stream = $this->streams->createLive(['title_en' => 'Eid live', 'title_ar' => 'بث العيد', 'media_mode' => 'both'], 1);
    $this->base = "/api/v1/uae-en/live-streams/{$this->stream->slug}";
});

function goLiveNow(): void
{
    test()->streams->goLive(test()->stream, 1);
    test()->stream->refresh();
}

it('lets guests join a live broadcast with a signed peer token, ICE servers and the socket', function () {
    $this->postJson("{$this->base}/join")->assertConflict()->assertJsonPath('error.code', 'stream_not_live');
    goLiveNow();

    $join = $this->postJson("{$this->base}/join")->assertOk()
        ->assertJsonPath('data.channel', fn (string $channel): bool => str_starts_with($channel, "private-live.{$this->stream->slug}.peer."))
        ->assertJsonPath('data.ice_servers.0.urls.0', 'stun:stun.l.google.com:19302')
        ->assertJsonPath('data.max_viewers', 20)
        ->assertJsonPath('data.viewers', 1)
        ->assertJsonPath('data.host_online', false)
        ->assertJsonPath('data.socket.key', 'test-key')
        ->json('data');

    expect(app(PeerTokens::class)->verify($join['peer_token'], 'uae', $this->stream->slug))->toBe($join['peer_id'])
        ->and(app(PeerTokens::class)->verify($join['peer_token'], 'ksa', $this->stream->slug))->toBeNull()
        ->and(app(PeerTokens::class)->verify($join['peer_token'], 'uae', 'other-show'))->toBeNull()
        ->and(app(PeerTokens::class)->verify($join['peer_token'].'x', 'uae', $this->stream->slug))->toBeNull();

    // Resuming with the token keeps the same slot.
    $this->postJson("{$this->base}/join", ['peer_id' => $join['peer_id'], 'peer_token' => $join['peer_token']])->assertOk()
        ->assertJsonPath('data.peer_id', $join['peer_id'])->assertJsonPath('data.viewers', 1);
    $this->getJson($this->base)->assertOk()->assertJsonPath('data.playback.webrtc', true)->assertJsonPath('data.playback.viewers', 1);

    // Recorded channels never use WebRTC.
    expect($this->streams->playback($this->stream)['type'])->toBe('live');
});

it('expires peer tokens', function () {
    goLiveNow();
    $join = $this->postJson("{$this->base}/join")->assertOk()->json('data');

    $this->travel(11)->minutes();

    expect(app(PeerTokens::class)->verify($join['peer_token'], 'uae', $this->stream->slug))->toBeNull();
    $this->postJson("{$this->base}/signal", ['peer_token' => $join['peer_token'], 'type' => 'ready'])
        ->assertForbidden()->assertJsonPath('error.code', 'peer_token_invalid');
});

it('renews the peer token with the presence heartbeat', function () {
    goLiveNow();
    $join = $this->postJson("{$this->base}/join")->assertOk()->json('data');
    $this->travel(60)->seconds();

    $renewed = $this->postJson("{$this->base}/presence", ['viewer_id' => 'abcdefgh1234', 'peer_token' => $join['peer_token']])->assertOk()->json('data');
    $this->travel(570)->seconds();

    expect($renewed['peer_token'])->not->toBe($join['peer_token'])
        ->and(app(PeerTokens::class)->verify($renewed['peer_token'], 'uae', $this->stream->slug))->toBe($join['peer_id'])
        ->and(app(PeerTokens::class)->verify($join['peer_token'], 'uae', $this->stream->slug))->toBeNull();
    $this->postJson("{$this->base}/presence", ['viewer_id' => 'abcdefgh1234'])->assertOk()->assertJsonMissingPath('data.peer_token');
});

it('refuses joins beyond the per-stream viewer cap with stream_full until a slot frees up', function () {
    app(SettingsService::class)->set('live.webrtc_max_viewers', 2, $this->country);
    goLiveNow();

    $first = $this->postJson("{$this->base}/join")->assertOk()->json('data');
    $this->postJson("{$this->base}/join")->assertOk();
    $this->postJson("{$this->base}/join")->assertConflict()->assertJsonPath('error.code', 'stream_full')->assertJsonPath('error.max_viewers', 2);
    $this->getJson($this->base)->assertJsonPath('data.playback.full', true);

    $this->postJson("{$this->base}/signal", ['peer_token' => $first['peer_token'], 'type' => 'leave'])->assertStatus(202);
    $this->postJson("{$this->base}/join")->assertOk();

    // A slot without a heartbeat for the peer TTL frees up too.
    $this->travel(2)->minutes();
    $this->postJson("{$this->base}/join")->assertOk()->assertJsonPath('data.viewers', 1);
});

it('authorises a viewer peer channel only with that peer token, and the host channel only for live-media managers', function () {
    goLiveNow();
    $join = $this->postJson("{$this->base}/join")->assertOk()->json('data');
    $other = $this->postJson("{$this->base}/join")->assertOk()->json('data');
    $auth = fn (string $channel, ?string $token) => $this->postJson('/api/v1/uae-en/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel, 'peer_token' => $token]);

    $auth($join['channel'], $join['peer_token'])->assertOk()
        ->assertExactJson(['auth' => 'test-key:'.hash_hmac('sha256', '1234.5678:'.$join['channel'], 'test-secret')]);
    $auth($join['channel'], $other['peer_token'])->assertForbidden();
    $auth($join['channel'], null)->assertForbidden();
    $auth("private-live.{$this->stream->slug}.host", $join['peer_token'])->assertUnauthorized();

    // The host channel through the panel session: managers only; nobody joins a peer channel that way.
    $host = "private-live.{$this->stream->slug}.host";
    $this->actingAs(adminAccount('uae', 'content'))->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $host])->assertOk();
    $this->actingAs(adminAccount('uae', 'content'))->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $join['channel']])->assertForbidden();
    $this->actingAs(adminAccount('uae', 'marketing'))->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $host])->assertForbidden();
});

it('relays viewer signals to the host channel and host signals to that viewer only', function () {
    goLiveNow();
    $join = $this->postJson("{$this->base}/join")->assertOk()->json('data');
    $slug = $this->stream->slug;

    $this->postJson("{$this->base}/signal", ['peer_token' => $join['peer_token'], 'type' => 'ready'])->assertStatus(202);
    $this->postJson("{$this->base}/signal", ['peer_token' => $join['peer_token'], 'type' => 'answer', 'sdp' => 'v=0 answer'])->assertStatus(202);
    $this->postJson("{$this->base}/signal", ['peer_token' => $join['peer_token'], 'type' => 'candidate', 'candidate' => ['candidate' => 'candidate:1 1 udp 1 1.2.3.4 5000 typ host', 'sdpMid' => '0', 'sdpMLineIndex' => 0]])->assertStatus(202);
    $this->postJson("{$this->base}/signal", ['peer_token' => $join['peer_token'], 'type' => 'offer', 'sdp' => 'x'])->assertUnprocessable();
    $this->postJson("{$this->base}/signal", ['peer_token' => 'v1.forged.token', 'type' => 'ready'])->assertForbidden();

    Event::assertDispatched(LiveSignal::class, fn (LiveSignal $event): bool => $event->broadcastOn()[0]->name === "private-live.{$slug}.host"
        && $event->broadcastAs() === 'signal' && $event->payload === ['peer' => $join['peer_id'], 'type' => 'ready']);
    Event::assertDispatched(LiveSignal::class, fn (LiveSignal $event): bool => $event->payload['type'] === 'answer' && $event->payload['sdp'] === 'v=0 answer');
    Event::assertDispatched(LiveSignal::class, fn (LiveSignal $event): bool => $event->payload['type'] === 'candidate' && $event->payload['candidate']['sdpMLineIndex'] === 0);

    $content = adminAccount('uae', 'content');
    $this->actingAs($content)->postJson(onPanel('admin', "media/{$this->stream->id}/webrtc/signal"), ['peer' => $join['peer_id'], 'type' => 'offer', 'sdp' => 'v=0 offer'])->assertStatus(202);
    $this->actingAs($content)->postJson(onPanel('admin', "media/{$this->stream->id}/webrtc/signal"), ['peer' => 'nosuchpeer123', 'type' => 'offer', 'sdp' => 'v=0'])->assertStatus(410);
    $this->actingAs(adminAccount('uae', 'marketing'))->postJson(onPanel('admin', "media/{$this->stream->id}/webrtc/signal"), ['peer' => $join['peer_id'], 'type' => 'offer', 'sdp' => 'v=0'])->assertForbidden();

    Event::assertDispatched(LiveSignal::class, fn (LiveSignal $event): bool => $event->broadcastOn()[0]->name === "private-live.{$slug}.peer.{$join['peer_id']}"
        && $event->payload === ['type' => 'offer', 'sdp' => 'v=0 offer']);
    $this->getJson($this->base)->assertJsonPath('data.playback.host_online', true);
});

it('tells viewers to restart when the studio comes online and that the show ended', function () {
    goLiveNow();
    $join = $this->postJson("{$this->base}/join")->assertOk()->json('data');
    $content = adminAccount('uae', 'content');
    $peerChannel = "private-live.{$this->stream->slug}.peer.{$join['peer_id']}";

    $this->actingAs($content)->postJson(onPanel('admin', "media/{$this->stream->id}/webrtc/online"), ['restart' => true])->assertOk()
        ->assertJsonPath('viewers', 1)->assertJsonPath('peers.0.id', $join['peer_id']);
    Event::assertDispatched(LiveSignal::class, fn (LiveSignal $event): bool => $event->broadcastOn()[0]->name === $peerChannel && $event->payload === ['type' => 'online']);

    $this->actingAs($content)->postJson(onPanel('admin', "media/{$this->stream->id}/end"))->assertOk()->assertJsonPath('status', 'ended');
    Event::assertDispatched(LiveSignal::class, fn (LiveSignal $event): bool => $event->broadcastOn()[0]->name === $peerChannel && $event->payload === ['type' => 'ended']);
    Event::assertDispatched(LiveStreamUpdated::class, fn (LiveStreamUpdated $event): bool => $event->name === 'status.changed' && $event->payload === ['status' => 'ended']);
    $this->postJson("{$this->base}/signal", ['peer_token' => $join['peer_token'], 'type' => 'ready'])->assertConflict()->assertJsonPath('error.code', 'stream_not_live');
});

it('shows the studio to managers and saves the viewer cap per country', function () {
    $content = adminAccount('uae', 'content');
    $this->actingAs($content)->get(onPanel('admin', "media/{$this->stream->id}"))->assertOk()
        ->assertSee('data-live-studio', false)->assertSee('webrtc\/signal', false)->assertSee('stun:stun.l.google.com:19302', false);
    $this->actingAs(adminAccount('uae', 'marketing'))->get(onPanel('admin', "media/{$this->stream->id}"))->assertOk()->assertDontSee('data-live-studio', false);

    $this->actingAs($content)->post(onPanel('admin', 'media/webrtc-settings'), ['max_viewers' => 35])->assertRedirect();
    expect(app(SettingsService::class)->get('live.webrtc_max_viewers', null, $this->country))->toBe(35);
});

it('builds STUN and TURN servers with static or time-limited coturn credentials', function () {
    config(['nawy.streams.webrtc.stun_urls' => 'stun:a.example:3478, stun:b.example:3478', 'nawy.streams.webrtc.turn_url' => 'turn:turn.example:3478?transport=udp,turns:turn.example:5349']);
    expect(app(IceServers::class)->for('peer1'))->toBe([['urls' => ['stun:a.example:3478', 'stun:b.example:3478']]]);

    config(['nawy.streams.webrtc.turn_username' => 'nawy', 'nawy.streams.webrtc.turn_credential' => 'pass']);
    expect(app(IceServers::class)->for('peer1')[1])->toBe(['urls' => ['turn:turn.example:3478?transport=udp', 'turns:turn.example:5349'], 'username' => 'nawy', 'credential' => 'pass']);

    $this->freezeTime();
    config(['nawy.streams.webrtc.turn_secret' => 'coturn-secret', 'nawy.streams.webrtc.turn_ttl_seconds' => 600]);
    $turn = app(IceServers::class)->for('peer1')[1];
    expect($turn['username'])->toBe((now()->getTimestamp() + 600).':peer1')
        ->and($turn['credential'])->toBe(base64_encode(hash_hmac('sha1', $turn['username'], 'coturn-secret', true)));
});

it('has no LiveKit configuration left', function () {
    expect(config('services.livekit'))->toBeNull()
        ->and(config('nawy.streams.driver'))->toBeNull()
        ->and(config('nawy.streams.hls_url_template'))->toBeNull()
        ->and(class_exists('App\\Domains\\LiveMedia\\Support\\LiveKitProvider'))->toBeFalse()
        ->and(interface_exists('App\\Domains\\LiveMedia\\Contracts\\LiveMediaProvider'))->toBeFalse()
        ->and(file_get_contents(base_path('.env.example')))->not->toContain('LIVEKIT')
        ->and(file_get_contents(base_path('.env.example')))->toContain('NAWY_WEBRTC_STUN_URLS');
});
