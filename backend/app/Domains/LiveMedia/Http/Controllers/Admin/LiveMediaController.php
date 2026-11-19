<?php

namespace App\Domains\LiveMedia\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryClock;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\LiveMedia\Enums\StreamCommentStatus;
use App\Domains\LiveMedia\Enums\StreamSource;
use App\Domains\LiveMedia\Enums\StreamStatus;
use App\Domains\LiveMedia\Exceptions\LiveSignalRefused;
use App\Domains\LiveMedia\Models\LiveStream;
use App\Domains\LiveMedia\Models\LiveStreamComment;
use App\Domains\LiveMedia\Support\IceServers;
use App\Domains\LiveMedia\Support\LiveStreamEngagement;
use App\Domains\LiveMedia\Support\LiveStreams;
use App\Domains\LiveMedia\Support\WebRtcSignalling;
use App\Domains\Settings\SettingsService;
use App\Http\Controllers\Controller;
use App\Shared\StateMachine\StateTransition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Content → Live & videos (SRS §14, ADR 11): the only place where
 * live broadcasts and recorded channels are created, scheduled (country
 * time with the Oman label), linked to products and started / ended —
 * live broadcasts from the browser studio (native WebRTC, D18).
 */
class LiveMediaController extends Controller
{
    private const LOCAL_FORMAT = 'Y-m-d\TH:i';

    public function __construct(private CountryContext $countryContext, private LiveStreams $streams, private CountryClock $clock) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'source' => ['nullable', Rule::enum(StreamSource::class)],
            'status' => ['nullable', Rule::enum(StreamStatus::class)],
        ]);

        return view('panels.admin.media.index', [
            'streams' => LiveStream::query()
                ->when($filters['source'] ?? null, fn ($query, string $source) => $query->where('source', $source))
                ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
                ->withCount('listings')->latest('id')->paginate(25)->withQueryString(),
            'filters' => $filters,
            'country' => $this->countryContext->current(),
            'clock' => $this->clock,
            'formats' => config('nawy.streams.recorded_formats'),
            'floating' => app(LiveStreams::class)->floatingSetting($this->countryContext->current()),
            'maxViewers' => app(WebRtcSignalling::class)->maxViewers(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $source = StreamSource::from($request->validate(['source' => ['required', Rule::enum(StreamSource::class)]])['source']);
        $isRecorded = $source === StreamSource::Recorded;
        $data = $request->validate([
            'title_en' => ['required', 'string', 'max:255'],
            'title_ar' => ['required', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            'description_ar' => ['nullable', 'string', 'max:5000'],
            'media_mode' => [Rule::requiredIf(! $isRecorded), 'nullable', Rule::in(['audio', 'video', 'both'])],
            'playback_mode' => ['nullable', Rule::in(['synced', 'free'])],
            'scheduled_at' => [Rule::requiredIf($isRecorded), 'nullable', 'date_format:'.self::LOCAL_FORMAT],
            'media' => [Rule::requiredIf($isRecorded), 'nullable', 'file', 'extensions:'.implode(',', config('nawy.streams.recorded_formats')), 'max:'.(config('nawy.streams.recorded_max_mb') * 1024)],
            'duration_seconds' => ['nullable', 'integer', 'min:1', 'max:86400'],
            'thumbnail' => ['nullable', 'image', 'max:4096'],
        ]);
        $data['scheduled_at'] = isset($data['scheduled_at']) ? $this->clock->localToUtc($this->countryContext->current(), $data['scheduled_at']) : null;
        $userId = $request->user()->id;

        $stream = $isRecorded
            ? $this->streams->createRecorded($data, $request->file('media'), isset($data['duration_seconds']) ? (int) $data['duration_seconds'] : null, $userId, $request->file('thumbnail'))
            : $this->streams->createLive($data, $userId, $request->file('thumbnail'));

        return redirect()->route('admin.media.show', $stream->id)->with('status', __('Saved.'));
    }

    public function show(Request $request, int $stream, IceServers $ice): View
    {
        $stream = LiveStream::query()->with('listings.variant.product')->findOrFail($stream);
        $canManage = $request->user()->can('live_media.manage');

        return view('panels.admin.media.show', [
            'stream' => $stream,
            'history' => StateTransition::query()->where('subject_type', $stream->transitionSubject())->where('subject_id', $stream->id)->orderBy('id')->get(),
            'studio' => $canManage && ! $stream->isRecorded() && $stream->status !== StreamStatus::Ended
                ? [
                    'slug' => $stream->slug,
                    'status' => $stream->status->value,
                    'media_mode' => $stream->media_mode,
                    'ice_servers' => $ice->for('host-'.$request->user()->id),
                    'max_viewers' => app(WebRtcSignalling::class)->maxViewers(),
                    'channel' => WebRtcSignalling::hostChannel($stream->slug),
                    'labels' => [
                        'ready' => __('Camera ready. Go live when you are.'),
                        'live' => __('You are live. Viewers connect to this browser — keep this tab open.'),
                        'ended' => __('Stream ended.'),
                        'failed' => __('That did not work. Try again.'),
                        'noRealtime' => __('Realtime (Reverb) is off, so viewers cannot connect. Turn on VITE_REVERB_ENABLED and rebuild the panels.'),
                        'socketError' => __('The realtime connection dropped; reconnecting…'),
                        'cameraNeeded' => __('Start the camera to resume the show.'),
                        'cameraError' => __('The camera or microphone could not start.'),
                        'confirmEnd' => __('End the show for everyone?'),
                        'mute' => __('Mute mic'),
                        'unmute' => __('Unmute mic'),
                        'share' => __('Share screen'),
                        'stopShare' => __('Stop sharing'),
                        'noViewers' => __('No viewers connected yet.'),
                        'insecure' => __('The studio needs a secure (HTTPS) page to use the camera and microphone.'),
                        'states' => ['new' => __('New'), 'connecting' => __('Connecting'), 'connected' => __('Connected'), 'disconnected' => __('Reconnecting'), 'failed' => __('Failed')],
                    ],
                    'routes' => [
                        'live' => route('admin.media.live', $stream->id),
                        'end' => route('admin.media.end', $stream->id),
                        'signal' => route('admin.media.webrtc.signal', $stream->id),
                        'online' => route('admin.media.webrtc.online', $stream->id),
                    ],
                ]
                : null,
            'country' => $this->countryContext->current(),
            'clock' => $this->clock,
            'comments' => LiveStreamComment::query()->where('live_stream_id', $stream->id)->latest('id')->limit(100)->get(),
            'viewersNow' => app(LiveStreamEngagement::class)->currentViewers($stream),
        ]);
    }

    /**
     * QC-063: the home floating player is a country setting, not a page
     * block — off, the stream that is live now (`auto`), or one stream.
     */
    public function floating(Request $request, SettingsService $settings): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(LiveStreams::FLOATING_MODES)],
            'stream' => ['nullable', 'required_if:mode,stream', 'string', Rule::exists(LiveStream::class, 'slug')],
        ]);
        $country = $this->countryContext->current();
        $settings->set('live.floating_mode', $data['mode'], $country, $request->user()->id);
        $settings->set('live.floating_stream', $data['mode'] === 'stream' ? $data['stream'] : '', $country, $request->user()->id);

        return back()->with('status', __('Home floating player saved.'));
    }

    /**
     * QC-040: show a held comment, or hide one (admin-only moderation).
     */
    public function moderateComment(Request $request, int $stream, int $comment, LiveStreamEngagement $engagement): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([StreamCommentStatus::Visible->value, StreamCommentStatus::Hidden->value])],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $model = LiveStreamComment::query()->where('live_stream_id', $stream)->findOrFail($comment);
        $engagement->moderate($model, StreamCommentStatus::from($data['status']), $request->user()->id, $data['reason'] ?? null);

        return back()->with('status', $data['status'] === 'hidden' ? __('The comment is hidden.') : __('The comment is shown.'));
    }

    public function update(Request $request, int $stream): RedirectResponse
    {
        $data = $request->validate([
            'title_en' => ['required', 'string', 'max:255'],
            'title_ar' => ['required', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            'description_ar' => ['nullable', 'string', 'max:5000'],
            'playback_mode' => ['nullable', Rule::in(['synced', 'free'])],
            'scheduled_at' => ['nullable', 'date_format:'.self::LOCAL_FORMAT],
            'thumbnail' => ['nullable', 'image', 'max:4096'],
        ]);
        $data['scheduled_at'] = isset($data['scheduled_at']) ? $this->clock->localToUtc($this->countryContext->current(), $data['scheduled_at']) : null;
        $this->streams->update(LiveStream::query()->findOrFail($stream), array_filter($data, fn ($value): bool => $value !== null) + ['scheduled_at' => $data['scheduled_at']], $request->user()->id, $request->file('thumbnail'));

        return back()->with('status', __('Saved.'));
    }

    /**
     * Go Live; the studio calls it with fetch (JSON) to keep its camera running.
     */
    public function goLive(Request $request, int $stream): RedirectResponse|JsonResponse
    {
        $model = $this->streams->goLive(LiveStream::query()->findOrFail($stream), $request->user()->id);

        return $request->expectsJson()
            ? response()->json(['status' => $model->status->value, 'message' => __('You are live.')])
            : back()->with('status', __('You are live.'));
    }

    /**
     * End the show: every WebRTC viewer is told (`ended`) and slots are freed.
     */
    public function end(Request $request, int $stream): RedirectResponse|JsonResponse
    {
        $model = $this->streams->end(LiveStream::query()->findOrFail($stream), $request->user()->id);

        return $request->expectsJson()
            ? response()->json(['status' => $model->status->value, 'message' => __('Stream ended.')])
            : back()->with('status', __('Stream ended.'));
    }

    /**
     * D18: the host studio's offer / ICE candidate for one viewer.
     */
    public function webrtcSignal(Request $request, int $stream, WebRtcSignalling $webrtc): JsonResponse
    {
        $data = $request->validate([
            'peer' => ['required', 'string', 'alpha_num', 'max:40'],
            'type' => ['required', Rule::in(WebRtcSignalling::HOST_TYPES)],
            'sdp' => ['nullable', 'required_if:type,offer', 'string', 'max:20000'],
            'candidate' => ['nullable', 'required_if:type,candidate', 'array'],
            'candidate.candidate' => ['nullable', 'string', 'max:1000'],
            'candidate.sdpMid' => ['nullable', 'string', 'max:64'],
            'candidate.sdpMLineIndex' => ['nullable', 'integer', 'min:0', 'max:64'],
        ]);

        try {
            $webrtc->fromHost(LiveStream::query()->findOrFail($stream), $data['peer'], $data['type'], $data);
        } catch (LiveSignalRefused $refused) {
            return response()->json(['error' => ['code' => $refused->reason, 'message' => $refused->getMessage()]], $refused->status);
        }

        return response()->json(['ok' => true], 202);
    }

    /**
     * D18: host studio heartbeat (every 15 s) → connected viewer slots;
     * `restart` (studio opened / reconnected) tells viewers to start over.
     */
    public function webrtcOnline(Request $request, int $stream, WebRtcSignalling $webrtc): JsonResponse
    {
        try {
            return response()->json($webrtc->hostHeartbeat(LiveStream::query()->findOrFail($stream), $request->boolean('restart')));
        } catch (LiveSignalRefused $refused) {
            return response()->json(['error' => ['code' => $refused->reason, 'message' => $refused->getMessage()]], $refused->status);
        }
    }

    /**
     * D18: the WebRTC viewer cap per stream (country setting `live.webrtc_max_viewers`).
     */
    public function webrtcSettings(Request $request, SettingsService $settings): RedirectResponse
    {
        $data = $request->validate(['max_viewers' => ['required', 'integer', 'min:1', 'max:200']]);
        $settings->set('live.webrtc_max_viewers', (int) $data['max_viewers'], $this->countryContext->current(), $request->user()->id);

        return back()->with('status', __('Saved.'));
    }

    public function products(Request $request, int $stream): RedirectResponse
    {
        $data = $request->validate(['listing_ids' => ['nullable', 'string', 'max:2000']]);
        $ids = array_filter(preg_split('/[\s,]+/', (string) ($data['listing_ids'] ?? '')) ?: [], fn (string $id): bool => ctype_digit($id));
        $this->streams->linkProducts(LiveStream::query()->findOrFail($stream), array_map('intval', $ids));

        return back()->with('status', __('Products linked.'));
    }
}
