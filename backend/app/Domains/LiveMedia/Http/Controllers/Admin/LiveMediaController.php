<?php

namespace App\Domains\LiveMedia\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryClock;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\LiveMedia\Contracts\LiveMediaProvider;
use App\Domains\LiveMedia\Enums\StreamSource;
use App\Domains\LiveMedia\Enums\StreamStatus;
use App\Domains\LiveMedia\Models\LiveStream;
use App\Domains\LiveMedia\Support\LiveStreams;
use App\Http\Controllers\Controller;
use App\Shared\StateMachine\StateTransition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Content → Live & videos (SRS §14, ADR 11): the only place where
 * live broadcasts and recorded channels are created, scheduled (country
 * time with the Oman label), linked to products and started / ended.
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

    public function show(Request $request, int $stream, LiveMediaProvider $provider): View
    {
        $stream = LiveStream::query()->with('listings.variant.product')->findOrFail($stream);
        $canManage = $request->user()->can('live_media.manage');

        return view('panels.admin.media.show', [
            'stream' => $stream,
            'history' => StateTransition::query()->where('subject_type', $stream->transitionSubject())->where('subject_id', $stream->id)->orderBy('id')->get(),
            'studio' => $canManage && ! $stream->isRecorded() && $stream->status !== StreamStatus::Ended
                ? ['url' => $provider->clientUrl(), 'room' => $stream->room_name, 'token' => $this->streams->publisherToken($stream, $request->user()->id)]
                : null,
            'country' => $this->countryContext->current(),
            'clock' => $this->clock,
        ]);
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

    public function goLive(Request $request, int $stream): RedirectResponse
    {
        $this->streams->goLive(LiveStream::query()->findOrFail($stream), $request->user()->id);

        return back()->with('status', __('You are live.'));
    }

    public function end(Request $request, int $stream): RedirectResponse
    {
        $this->streams->end(LiveStream::query()->findOrFail($stream), $request->user()->id);

        return back()->with('status', __('Stream ended.'));
    }

    public function products(Request $request, int $stream): RedirectResponse
    {
        $data = $request->validate(['listing_ids' => ['nullable', 'string', 'max:2000']]);
        $ids = array_filter(preg_split('/[\s,]+/', (string) ($data['listing_ids'] ?? '')) ?: [], fn (string $id): bool => ctype_digit($id));
        $this->streams->linkProducts(LiveStream::query()->findOrFail($stream), array_map('intval', $ids));

        return back()->with('status', __('Products linked.'));
    }
}
