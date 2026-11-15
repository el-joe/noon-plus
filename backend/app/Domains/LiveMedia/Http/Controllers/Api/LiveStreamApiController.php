<?php

namespace App\Domains\LiveMedia\Http\Controllers\Api;

use App\Domains\Catalog\Support\ListingCards;
use App\Domains\Listings\Models\Listing;
use App\Domains\LiveMedia\Enums\StreamStatus;
use App\Domains\LiveMedia\Models\LiveStream;
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
    public function __construct(private LiveStreams $streams) {}

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

        return ApiResponse::success(['likes' => $stream->likes_count]);
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
        ];
    }
}
