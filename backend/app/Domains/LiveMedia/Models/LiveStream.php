<?php

namespace App\Domains\LiveMedia\Models;

use App\Domains\Listings\Models\Listing;
use App\Domains\LiveMedia\Enums\StreamSource;
use App\Domains\LiveMedia\Enums\StreamStatus;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * An admin-made live broadcast or recorded channel (SRS §14, ADR 11 —
 * vendors and marketers never create these). Products are linked for the
 * shoppable player.
 */
#[Fillable([
    'slug', 'source', 'title_en', 'title_ar', 'description_en', 'description_ar', 'thumbnail_path', 'media_mode', 'status',
    'scheduled_at', 'scheduled_end_at', 'started_at', 'ended_at', 'stream_key', 'room_name', 'provider_room_id', 'media_path',
    'media_mime', 'duration_seconds', 'playback_mode', 'peak_viewers', 'total_viewers', 'likes_count', 'created_by',
])]
#[Hidden(['stream_key'])]
class LiveStream extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => StreamSource::class,
            'status' => StreamStatus::class,
            'scheduled_at' => 'immutable_datetime',
            'scheduled_end_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsToMany<Listing, $this>
     */
    public function listings(): BelongsToMany
    {
        return $this->belongsToMany(Listing::class, 'live_stream_products')->withPivot('sort_order')->withTimestamps()->orderByPivot('sort_order');
    }

    public function title(): string
    {
        return app()->getLocale() === 'ar' ? $this->title_ar : $this->title_en;
    }

    public function isRecorded(): bool
    {
        return $this->source === StreamSource::Recorded;
    }
}
