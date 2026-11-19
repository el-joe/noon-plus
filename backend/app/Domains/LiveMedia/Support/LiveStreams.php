<?php

namespace App\Domains\LiveMedia\Support;

use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Listings\Models\Listing;
use App\Domains\LiveMedia\Enums\StreamSource;
use App\Domains\LiveMedia\Enums\StreamStatus;
use App\Domains\LiveMedia\Events\LiveStreamUpdated;
use App\Domains\LiveMedia\Models\LiveStream;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Settings\SettingsService;
use App\Shared\Broadcasting\Realtime;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Live streams and recorded channels (SRS §14, ADR 11 — admin only):
 * create, schedule, link products, Go Live / End for broadcasts (native
 * WebRTC from the admin studio, D18 — {@see WebRtcSignalling}), and the clock-driven lifecycle of
 * recorded channels (`streams:sync-recorded` every minute and lazily on
 * the public API). Playback of a channel is synced like radio by default.
 */
class LiveStreams
{
    public const DISK = 'public';

    public function __construct(
        private StateMachine $machine,
        private WebRtcSignalling $webrtc,
        private CountryContext $countryContext,
    ) {}

    /**
     * @param  array{title_en: string, title_ar: string, description_en?: string|null, description_ar?: string|null, media_mode?: string, scheduled_at?: CarbonImmutable|null}  $data
     */
    public function createLive(array $data, int $adminUserId, ?UploadedFile $thumbnail = null): LiveStream
    {
        return DB::connection((new LiveStream)->getConnectionName())->transaction(function () use ($data, $adminUserId, $thumbnail): LiveStream {
            $slug = $this->slug($data['title_en']);
            $room = $this->countryContext->current()->code.'-'.$slug;
            $stream = LiveStream::query()->create([
                'slug' => $slug,
                'source' => StreamSource::Live,
                'title_en' => $data['title_en'],
                'title_ar' => $data['title_ar'],
                'description_en' => $data['description_en'] ?? null,
                'description_ar' => $data['description_ar'] ?? null,
                'media_mode' => $data['media_mode'] ?? 'video',
                'status' => isset($data['scheduled_at']) ? StreamStatus::Scheduled : StreamStatus::Idle,
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'stream_key' => Str::random(40),
                'room_name' => $room,
                'thumbnail_path' => $thumbnail?->store($this->folder(), self::DISK),
                'created_by' => $adminUserId,
            ]);
            $this->machine->created($stream, ActorType::Admin, $adminUserId);

            return $stream;
        });
    }

    /**
     * A scheduled channel from an uploaded file (FR-LS-6/7): the duration
     * comes from ffprobe, else the one measured in the browser; none →
     * refused. Ends at `scheduled_at + duration`.
     *
     * @param  array{title_en: string, title_ar: string, description_en?: string|null, description_ar?: string|null, scheduled_at: CarbonImmutable, playback_mode?: string|null}  $data
     */
    public function createRecorded(array $data, UploadedFile $media, ?int $measuredSeconds, int $adminUserId, ?UploadedFile $thumbnail = null): LiveStream
    {
        $duration = $this->probeDuration($media->getRealPath()) ?? ($measuredSeconds > 0 ? $measuredSeconds : null);

        if ($duration === null) {
            throw new TransitionRefused(__('The length of the media could not be read; the upload was refused.'));
        }

        $stream = DB::connection((new LiveStream)->getConnectionName())->transaction(function () use ($data, $media, $duration, $adminUserId, $thumbnail): LiveStream {
            $mime = (string) $media->getMimeType();
            $stream = LiveStream::query()->create([
                'slug' => $this->slug($data['title_en']),
                'source' => StreamSource::Recorded,
                'title_en' => $data['title_en'],
                'title_ar' => $data['title_ar'],
                'description_en' => $data['description_en'] ?? null,
                'description_ar' => $data['description_ar'] ?? null,
                'media_mode' => str_starts_with($mime, 'audio/') ? 'audio' : 'video',
                'status' => StreamStatus::Scheduled,
                'scheduled_at' => $data['scheduled_at'],
                'scheduled_end_at' => $data['scheduled_at']->addSeconds($duration),
                'duration_seconds' => $duration,
                'media_path' => $media->store($this->folder(), self::DISK),
                'media_mime' => mb_substr($mime, 0, 64),
                'playback_mode' => $data['playback_mode'] ?? config('nawy.streams.default_playback_mode'),
                'thumbnail_path' => $thumbnail?->store($this->folder(), self::DISK),
                'created_by' => $adminUserId,
            ]);
            $this->machine->created($stream, ActorType::Admin, $adminUserId);

            return $stream;
        });

        $this->syncOne($stream);

        return $stream->refresh();
    }

    /**
     * Edit texts, thumbnail, playback mode or the schedule. Re-timing a
     * channel moves its end; an ended channel moved to the future is
     * scheduled again (FR-LS-8). A broadcast can be re-timed until it goes live.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(LiveStream $stream, array $data, int $adminUserId, ?UploadedFile $thumbnail = null): LiveStream
    {
        $changes = array_intersect_key($data, array_flip(['title_en', 'title_ar', 'description_en', 'description_ar', 'playback_mode', 'media_mode']));

        if ($thumbnail !== null) {
            $changes['thumbnail_path'] = $thumbnail->store($this->folder(), self::DISK);
        }

        if (array_key_exists('scheduled_at', $data) && $data['scheduled_at'] !== null) {
            if (! $stream->isRecorded() && in_array($stream->status, [StreamStatus::Live, StreamStatus::Ended], true)) {
                throw new TransitionRefused(__('A broadcast that went live cannot be re-timed.'));
            }

            $changes['scheduled_at'] = $data['scheduled_at'];

            if ($stream->isRecorded()) {
                $changes['scheduled_end_at'] = $data['scheduled_at']->addSeconds((int) $stream->duration_seconds);
            }
        }

        $stream->forceFill($changes)->save();

        if (! $stream->isRecorded() && $stream->status === StreamStatus::Idle && $stream->scheduled_at !== null) {
            $this->machine->move($stream, StreamStatus::Scheduled, ActorType::Admin, $adminUserId);
        }

        if ($stream->isRecorded()) {
            $this->syncOne($stream);
        }

        return $stream->refresh();
    }

    /**
     * Go Live (FR-LS-2): broadcasts only (FR-LS-9); an ended one never again (FR-LS-4).
     */
    public function goLive(LiveStream $stream, int $adminUserId): LiveStream
    {
        if ($stream->isRecorded()) {
            throw new TransitionRefused(__('Recorded channels start and end on their schedule; Go Live is only for broadcasts.'));
        }

        if ($stream->status === StreamStatus::Ended) {
            throw new TransitionRefused(__('A stream that has ended cannot go live again.'));
        }

        $this->machine->move($stream, StreamStatus::Live, ActorType::Admin, $adminUserId, null, ['started_at' => now()]);
        Realtime::broadcast(new LiveStreamUpdated($stream->slug, 'status.changed', ['status' => StreamStatus::Live->value]));

        return $stream;
    }

    public function end(LiveStream $stream, int $adminUserId): LiveStream
    {
        if ($stream->isRecorded()) {
            throw new TransitionRefused(__('Recorded channels end on their schedule.'));
        }

        $this->machine->move($stream, StreamStatus::Ended, ActorType::Admin, $adminUserId, null, ['ended_at' => now()]);

        // D18: every WebRTC viewer is told the show is over; slots are freed.
        $this->webrtc->ended($stream);
        Realtime::broadcast(new LiveStreamUpdated($stream->slug, 'status.changed', ['status' => StreamStatus::Ended->value]));

        return $stream;
    }

    /**
     * Recorded channel transitions for the current country (every minute).
     */
    public function syncRecorded(): int
    {
        $moved = 0;

        // WP11: only streams that can still move (ended ones only when re-timed to the future); public GETs call this too.
        LiveStream::query()->where('source', StreamSource::Recorded)
            ->where(fn ($query) => $query->where('status', '!=', StreamStatus::Ended)->orWhere('scheduled_end_at', '>', now()))
            ->each(function (LiveStream $stream) use (&$moved): void {
                $moved += $this->syncOne($stream);
            });

        return $moved;
    }

    /**
     * @return int moves made
     */
    public function syncOne(LiveStream $stream): int
    {
        if (! $stream->isRecorded() || $stream->scheduled_at === null || $stream->scheduled_end_at === null) {
            return 0;
        }

        $now = CarbonImmutable::now();
        $moves = 0;

        if ($stream->status === StreamStatus::Ended && $stream->scheduled_end_at->greaterThan($now)) {
            $this->machine->move($stream, StreamStatus::Scheduled, ActorType::System, null, __('Re-timed to the future'), ['started_at' => null, 'ended_at' => null]);
            $moves++;
        }

        if ($stream->status === StreamStatus::Scheduled && $stream->scheduled_at->lessThanOrEqualTo($now)) {
            $this->machine->move($stream, StreamStatus::Live, ActorType::System, null, null, ['started_at' => $stream->scheduled_at]);
            $moves++;
        }

        if ($stream->status === StreamStatus::Live && $stream->scheduled_end_at->lessThanOrEqualTo($now)) {
            $this->machine->move($stream, StreamStatus::Ended, ActorType::System, null, null, ['ended_at' => $stream->scheduled_end_at]);
            $moves++;
        }

        return $moves;
    }

    /**
     * Link catalogue listings to the stream's shoppable player, in this order.
     *
     * @param  array<int, int>  $listingIds
     */
    public function linkProducts(LiveStream $stream, array $listingIds): void
    {
        $listingIds = array_values(array_unique(array_map('intval', $listingIds)));

        if (Listing::query()->whereKey($listingIds)->count() !== count($listingIds)) {
            throw new TransitionRefused(__('Some products do not exist in this country.'));
        }

        $stream->listings()->sync(collect($listingIds)->mapWithKeys(fn (int $id, int $index): array => [$id => ['sort_order' => $index]])->all());
    }

    /**
     * What a viewer needs to play: a recorded channel's file, mode and the
     * synced offset (now − scheduled_at, capped at the duration — FR-LS-10);
     * a live broadcast's WebRTC state (D18): joinable while live, whether the
     * host studio is publishing, and the viewer cap.
     *
     * @return array<string, mixed>
     */
    public function playback(LiveStream $stream): array
    {
        if ($stream->isRecorded()) {
            $offset = 0;

            if ($stream->playback_mode === 'synced' && $stream->status === StreamStatus::Live) {
                $offset = (int) min((int) $stream->duration_seconds, max(0, $stream->scheduled_at->diffInSeconds(now())));
            }

            return [
                'type' => 'recorded',
                'media_url' => Storage::disk(self::DISK)->url((string) $stream->media_path),
                'mime' => $stream->media_mime,
                'mode' => $stream->playback_mode,
                'duration_seconds' => $stream->duration_seconds,
                'offset_seconds' => $offset,
            ];
        }

        $webrtc = $this->webrtc->isWebRtc($stream);
        $viewers = $webrtc ? $this->webrtc->viewers($stream) : 0;
        $max = $this->webrtc->maxViewers();

        return [
            'type' => 'live',
            'webrtc' => $webrtc,
            'host_online' => $webrtc && $this->webrtc->hostOnline($stream),
            'max_viewers' => $max,
            'viewers' => $viewers,
            'full' => $webrtc && $viewers >= $max,
            'join_url' => $webrtc ? 'live-streams/'.$stream->slug.'/join' : null,
        ];
    }

    public function probeDuration(string|false $path): ?int
    {
        if ($path === false) {
            return null;
        }

        try {
            $result = Process::timeout(20)->run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $path]);
        } catch (Throwable) {
            return null;
        }

        $seconds = trim($result->output());

        return $result->successful() && is_numeric($seconds) && (float) $seconds > 0 ? (int) ceil((float) $seconds) : null;
    }

    private function slug(string $title): string
    {
        return Str::limit(Str::slug($title), 60, '').'-'.Str::lower(Str::random(6));
    }

    private function folder(): string
    {
        return 'live/'.$this->countryContext->current()->code;
    }

    /**
     * QC-063: home floating player modes (country setting `live.floating_mode`).
     */
    public const FLOATING_MODES = ['off', 'auto', 'stream'];

    /**
     * @return array{mode: string, stream: string|null}
     */
    public function floatingSetting(Country $country): array
    {
        $settings = app(SettingsService::class);
        $mode = (string) $settings->get('live.floating_mode', 'auto', $country);

        return [
            'mode' => in_array($mode, self::FLOATING_MODES, true) ? $mode : 'auto',
            'stream' => ($stream = $settings->get('live.floating_stream', null, $country)) === '' ? null : $stream,
        ];
    }

    /**
     * The stream for the home floating player: the chosen one while it is
     * live or scheduled, else (auto) the stream that is live now.
     */
    public function floating(Country $country): ?LiveStream
    {
        $setting = $this->floatingSetting($country);

        return match ($setting['mode']) {
            'off' => null,
            'stream' => LiveStream::query()->where('slug', $setting['stream'])->whereIn('status', [StreamStatus::Live, StreamStatus::Scheduled])->first(),
            default => LiveStream::query()->where('status', StreamStatus::Live)->orderByDesc('started_at')->first(),
        };
    }
}
