<?php

use App\Domains\Countries\Support\CountryClock;
use App\Domains\LiveMedia\Contracts\LiveMediaProvider;
use App\Domains\LiveMedia\Enums\StreamSource;
use App\Domains\LiveMedia\Enums\StreamStatus;
use App\Domains\LiveMedia\Models\LiveStream;
use App\Domains\LiveMedia\Support\FakeLiveMediaProvider;
use App\Domains\LiveMedia\Support\LiveKitProvider;
use App\Domains\LiveMedia\Support\LiveStreams;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\StateMachine\StateTransition;
use App\Shared\StateMachine\TransitionRefused;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
    Storage::fake('public');
    Process::fake(['*' => Process::result(exitCode: 1)]);
    $this->provider = new FakeLiveMediaProvider;
    app()->instance(LiveMediaProvider::class, $this->provider);
    $this->streams = app(LiveStreams::class);
});

function recordedChannel(CarbonImmutable $startsAt, int $seconds = 600, string $mode = 'synced'): LiveStream
{
    return app(LiveStreams::class)->createRecorded(
        ['title_en' => 'Morning radio', 'title_ar' => 'راديو الصباح', 'scheduled_at' => $startsAt, 'playback_mode' => $mode],
        UploadedFile::fake()->create('show.mp3', 200, 'audio/mpeg'),
        $seconds,
        1,
    );
}

it('signs LiveKit tokens with HS256 and calls the room service with a server token', function () {
    Http::fake(['https://live.example.com/twirp/*' => Http::response(['sid' => 'RM_123', 'name' => 'uae-show'])]);
    $provider = new LiveKitProvider('https://live.example.com', 'APIkey', 'secret-value');

    expect($provider->createRoom('uae-show'))->toBe('RM_123')
        ->and($provider->clientUrl())->toBe('wss://live.example.com');

    Http::assertSent(function (HttpRequest $request): bool {
        [$header, $payload, $signature] = explode('.', str_replace('Bearer ', '', $request->header('Authorization')[0]));
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

        return $request->url() === 'https://live.example.com/twirp/livekit.RoomService/CreateRoom'
            && $request['name'] === 'uae-show'
            && $claims['iss'] === 'APIkey' && $claims['video']['roomCreate'] === true
            && hash_equals(rtrim(strtr(base64_encode(hash_hmac('sha256', "{$header}.{$payload}", 'secret-value', true)), '+/', '-_'), '='), $signature);
    });

    $token = $provider->accessToken('uae-show', 'user-5', 'Sara', false, 60);
    $claims = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
    expect($claims)->sub->toBe('user-5')
        ->and($claims['video'])->toMatchArray(['room' => 'uae-show', 'roomJoin' => true, 'canPublish' => false, 'canSubscribe' => true])
        ->and($claims['exp'] - $claims['nbf'])->toBe(3600);

    Http::fake(['https://down.example.com/*' => Http::response('nope', 500)]);
    expect(fn () => (new LiveKitProvider('https://down.example.com', 'APIkey', 'secret-value'))->deleteRoom('uae-show'))->toThrow(RuntimeException::class, 'DeleteRoom failed');
    expect(fn () => (new LiveKitProvider(null, null, null))->createRoom('x'))->toThrow(RuntimeException::class);
});

it('runs a live broadcast from creation to end and never live again', function () {
    $stream = $this->streams->createLive(['title_en' => 'Eid launch', 'title_ar' => 'إطلاق العيد', 'media_mode' => 'both'], 4);

    expect($stream->status)->toBe(StreamStatus::Idle)
        ->and($stream->source)->toBe(StreamSource::Live)
        ->and($stream->room_name)->toStartWith('uae-eid-launch-')
        ->and($this->provider->rooms)->toHaveKey($stream->room_name)
        ->and(strlen($stream->stream_key))->toBe(40);

    $this->streams->goLive($stream, 4);
    expect($stream->refresh()->status)->toBe(StreamStatus::Live)->and($stream->started_at)->not->toBeNull();
    expect(fn () => $this->streams->update($stream, ['scheduled_at' => CarbonImmutable::now()->addDay()], 4))->toThrow(TransitionRefused::class);

    $this->streams->end($stream, 4);
    expect($stream->refresh()->status)->toBe(StreamStatus::Ended)
        ->and($this->provider->rooms)->not->toHaveKey($stream->room_name)
        ->and(fn () => $this->streams->goLive($stream, 4))->toThrow(TransitionRefused::class, 'cannot go live again')
        ->and(StateTransition::query()->where('subject_type', 'live_stream')->where('subject_id', $stream->id)->pluck('to_status')->all())->toBe(['idle', 'live', 'ended']);

    $scheduled = $this->streams->createLive(['title_en' => 'Later', 'title_ar' => 'لاحقاً', 'scheduled_at' => CarbonImmutable::now()->addDay()], 4);
    expect($scheduled->status)->toBe(StreamStatus::Scheduled);
});

it('plays a recorded channel on its schedule, refuses Go Live and heals when re-timed', function () {
    $this->freezeTime();
    $channel = recordedChannel(CarbonImmutable::now()->addMinutes(10), 600);

    expect($channel->status)->toBe(StreamStatus::Scheduled)
        ->and($channel->media_mode)->toBe('audio')
        ->and($channel->scheduled_end_at->equalTo($channel->scheduled_at->addSeconds(600)))->toBeTrue()
        ->and(fn () => $this->streams->goLive($channel, 1))->toThrow(TransitionRefused::class, 'Go Live is only for broadcasts');
    Storage::disk('public')->assertExists($channel->media_path);

    $this->travel(12)->minutes();
    $this->artisan('nawy:streams:sync-recorded')->assertSuccessful();
    $channel->refresh();
    expect($channel->status)->toBe(StreamStatus::Live)
        ->and($this->streams->playback($channel, 'guest')['offset_seconds'])->toBe(120);

    $this->travel(10)->minutes();
    $this->artisan('nawy:streams:sync-recorded')->assertSuccessful();
    expect($channel->refresh()->status)->toBe(StreamStatus::Ended);

    $this->streams->update($channel, ['scheduled_at' => CarbonImmutable::now()->addHour()], 1);
    expect($channel->refresh()->status)->toBe(StreamStatus::Scheduled)
        ->and(StateTransition::query()->where('subject_id', $channel->id)->where('subject_type', 'live_stream')->latest('id')->value('actor_type'))->toBe('system');

    $free = recordedChannel(CarbonImmutable::now()->subMinute(), 600, 'free');
    expect($free->status)->toBe(StreamStatus::Live)->and($this->streams->playback($free, 'guest')['offset_seconds'])->toBe(0);
});

it('refuses a recorded upload whose length cannot be read and uses ffprobe when present', function () {
    expect(fn () => $this->streams->createRecorded(['title_en' => 'X', 'title_ar' => 'س', 'scheduled_at' => CarbonImmutable::now()->addDay()], UploadedFile::fake()->create('a.mp3', 10, 'audio/mpeg'), null, 1))
        ->toThrow(TransitionRefused::class, 'length');

    Process::fake(['*' => Process::result(output: "93.2\n")]);
    $channel = $this->streams->createRecorded(['title_en' => 'Y', 'title_ar' => 'ص', 'scheduled_at' => CarbonImmutable::now()->addDay()], UploadedFile::fake()->create('b.mp4', 10, 'video/mp4'), 5, 1);
    expect($channel->duration_seconds)->toBe(94)->and($channel->media_mode)->toBe('video');
});

it('serves streams with playback and linked products to customers of the same country only', function () {
    $listing = sellableListing('120.00', vendor: Vendor::factory()->create());
    $live = $this->streams->createLive(['title_en' => 'Live sale', 'title_ar' => 'تخفيضات مباشرة'], 1);
    $this->streams->linkProducts($live, [$listing->id]);
    expect(fn () => $this->streams->linkProducts($live, [999999]))->toThrow(TransitionRefused::class);

    $this->getJson("/api/v1/uae-en/live-streams/{$live->slug}")->assertOk()
        ->assertJsonPath('data.playback.token', null)
        ->assertJsonPath('data.products.0.listing_id', $listing->id);
    $this->postJson("/api/v1/uae-en/live-streams/{$live->slug}/likes")->assertNotFound();

    $this->streams->goLive($live, 1);
    $this->getJson("/api/v1/uae-ar/live-streams/{$live->slug}")->assertOk()
        ->assertJsonPath('data.title', 'تخفيضات مباشرة')
        ->assertJsonPath('data.playback.type', 'live')
        ->assertJsonPath('data.playback.room', $live->room_name)
        ->assertJsonPath('data.playback.token', fn (string $token): bool => str_starts_with($token, 'fake.') && ! json_decode(base64_decode(substr($token, 5)), true)['canPublish']);
    $this->postJson("/api/v1/uae-en/live-streams/{$live->slug}/likes")->assertOk()->assertJsonPath('data.likes', 1);

    recordedChannel(CarbonImmutable::now()->addDay());
    $this->getJson('/api/v1/uae-en/live-streams')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.status', 'live');

    $this->getJson('/api/v1/ksa-en/live-streams')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/ksa-en/live-streams/{$live->slug}")->assertNotFound();
});

it('keeps live media admin-only (ADR 11)', function () {
    [$seller] = sellerAccount('uae');
    $this->actingAs($seller)->get(onPanel('admin', 'media'))->assertForbidden();
    expect(collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn (string $name): bool => preg_match('/^(partner|marketer|travel_agency|carrier|delivery)\.(media|streams|live)/', $name) === 1))->toBeEmpty();

    $marketing = adminAccount('uae', 'marketing');
    $this->actingAs($marketing)->get(onPanel('admin', 'media'))->assertOk();
    $this->actingAs($marketing)->post(onPanel('admin', 'media'), ['source' => 'live', 'title_en' => 'X', 'title_ar' => 'س', 'media_mode' => 'video'])->assertForbidden();

    $content = adminAccount('uae', 'content');
    $local = app(CountryClock::class)->inCountry($this->country, CarbonImmutable::now()->addDay())->format('Y-m-d\TH:i');
    $this->actingAs($content)->post(onPanel('admin', 'media'), ['source' => 'live', 'title_en' => 'Studio test', 'title_ar' => 'اختبار', 'media_mode' => 'video', 'scheduled_at' => $local])->assertRedirect();
    $stream = LiveStream::query()->sole();
    expect($stream->status)->toBe(StreamStatus::Scheduled)
        ->and($stream->scheduled_at->format('Y-m-d H:i'))->toBe(CarbonImmutable::now()->addDay()->format('Y-m-d H:i'));

    $this->actingAs($content)->get(onPanel('admin', "media/{$stream->id}"))->assertOk()->assertSee('Publisher token');
    $this->actingAs($content)->post(onPanel('admin', "media/{$stream->id}/live"))->assertRedirect();
    expect($stream->refresh()->status)->toBe(StreamStatus::Live);

    $this->actingAs($content)->post(onPanel('admin', 'media'), ['source' => 'recorded', 'title_en' => 'R', 'title_ar' => 'ر', 'scheduled_at' => $local, 'media' => UploadedFile::fake()->create('clip.exe', 10)])
        ->assertSessionHasErrors('media');

    $ksaContent = adminAccount('ksa', 'content');
    $this->actingAs($ksaContent)->get(onPanel('admin', "media/{$stream->id}"))->assertNotFound();
});
