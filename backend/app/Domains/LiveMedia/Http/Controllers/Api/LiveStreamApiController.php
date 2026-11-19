<?php

namespace App\Domains\LiveMedia\Http\Controllers\Api;

use App\Domains\Catalog\Support\ListingCards;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Listings\Models\Listing;
use App\Domains\LiveMedia\Enums\StreamCommentStatus;
use App\Domains\LiveMedia\Enums\StreamStatus;
use App\Domains\LiveMedia\Models\LiveStream;
use App\Domains\LiveMedia\Models\LiveStreamComment;
use App\Domains\LiveMedia\Support\LiveStreamEngagement;
use App\Domains\LiveMedia\Support\LiveStreams;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Public live streams API (SRS §14.1–14.2 FR-LS-11, storefront `/live-streams`
 * and the app's Live context): live now, upcoming and recent; a stream with
 * its playback data and linked products; likes (throttled).
 */
class LiveStreamApiController extends Controller
{
    public function __construct(private LiveStreams $streams, private LiveStreamEngagement $engagement) {}

    public function index(): JsonResponse
    {
        $this->streams->syncRecorded();
        $streams = LiveStream::query()
            ->where(fn ($query) => $query->whereIn('status', [StreamStatus::Live, StreamStatus::Scheduled])
                ->orWhere(fn ($ended) => $ended->where('status', StreamStatus::Ended)->where('ended_at', '>=', now()->subDays(7))))
            ->orderByRaw("case status when 'live' then 0 when 'scheduled' then 1 else 2 end")
            ->orderBy('scheduled_at')
            ->limit(50)->get();

        return ApiResponse::success($streams->map(fn (LiveStream $stream): array => $this->summary($stream))->all());
    }

    /**
     * QC-063: the stream for the home floating mini-player (a country
     * setting, not a page block), or null.
     */
    public function floating(CountryContext $countryContext): JsonResponse
    {
        $this->streams->syncRecorded();
        $stream = $this->streams->floating($countryContext->current());

        return ApiResponse::success($stream === null ? null : $this->summary($stream));
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $stream = LiveStream::query()->where('slug', $slug)->firstOrFail();
        $this->streams->syncOne($stream);
        $identity = $request->user() !== null ? 'user-'.$request->user()->id : 'guest-'.Str::lower(Str::random(12));

        return ApiResponse::success($this->summary($stream) + [
            'description' => app()->getLocale() === 'ar' ? $stream->description_ar : $stream->description_en,
            'playback' => $this->streams->playback($stream, $identity),
            'products' => $stream->listings()->with(ListingCards::relations())->where('listings.status', 'active')->get()->map(fn (Listing $listing): array => ListingCards::card($listing))->all(),
        ]);
    }

    public function like(string $slug): JsonResponse
    {
        $stream = LiveStream::query()->where('slug', $slug)->where('status', StreamStatus::Live)->firstOrFail();
        $stream->increment('likes_count');
        $this->engagement->broadcastLikes($stream);

        return ApiResponse::success(['likes' => $stream->likes_count]);
    }

    /**
     * QC-040: visible comments, oldest first; `after_id` polls for new ones.
     */
    public function comments(Request $request, string $slug): JsonResponse
    {
        $stream = LiveStream::query()->where('slug', $slug)->firstOrFail();
        $afterId = $request->validate(['after_id' => ['nullable', 'integer', 'min:0']])['after_id'] ?? null;
        $comments = LiveStreamComment::query()->where('live_stream_id', $stream->id)->where('status', StreamCommentStatus::Visible)
            ->when($afterId !== null, fn ($query) => $query->where('id', '>', $afterId)->orderBy('id')->limit(100), fn ($query) => $query->latest('id')->limit(50))
            ->get()->sortBy('id')->values();

        return ApiResponse::success($comments->map(fn (LiveStreamComment $comment): array => $this->commentRow($comment))->all(), [
            'viewers' => $this->engagement->currentViewers($stream),
            'likes' => $stream->likes_count,
        ]);
    }

    /**
     * QC-040: a signed-in viewer comments while the stream is live. A
     * comment caught by the word / link filter is `held` for moderation
     * until an admin shows it (the answer's `status` says so).
     */
    public function comment(Request $request, string $slug): JsonResponse
    {
        $stream = LiveStream::query()->where('slug', $slug)->firstOrFail();
        $body = $request->validate(['body' => ['required', 'string', 'min:1', 'max:300']])['body'];
        $comment = $this->engagement->comment($stream, $request->user(), trim($body));

        return ApiResponse::success($this->commentRow($comment) + ['status' => $comment->status->value], status: 201);
    }

    /**
     * QC-040: viewer heartbeat (every ~30 s while watching) → live viewer count.
     */
    public function presence(Request $request, string $slug): JsonResponse
    {
        $stream = LiveStream::query()->where('slug', $slug)->firstOrFail();
        $viewerId = $request->validate(['viewer_id' => ['nullable', 'string', 'min:8', 'max:64']])['viewer_id'] ?? null;
        $key = $request->user('sanctum') !== null ? 'user-'.$request->user('sanctum')->id : 'guest-'.($viewerId ?? $request->ip());

        return ApiResponse::success(['viewers' => $this->engagement->heartbeat($stream, $key)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function commentRow(LiveStreamComment $comment): array
    {
        return LiveStreamEngagement::publicRow($comment);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(LiveStream $stream): array
    {
        return [
            'slug' => $stream->slug,
            'type' => $stream->source->value,
            'title' => $stream->title(),
            'status' => $stream->status->value,
            'media_mode' => $stream->media_mode,
            'thumbnail_url' => $stream->thumbnail_path === null ? null : Storage::disk(LiveStreams::DISK)->url($stream->thumbnail_path),
            'scheduled_at' => $stream->scheduled_at?->toIso8601String(),
            'scheduled_end_at' => $stream->scheduled_end_at?->toIso8601String(),
            'started_at' => $stream->started_at?->toIso8601String(),
            'likes' => $stream->likes_count,
            'viewers' => $this->engagement->currentViewers($stream),
        ];
    }
}
