<?php

namespace App\Domains\LiveMedia\Models;

use App\Domains\LiveMedia\Enums\StreamCommentStatus;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A viewer's comment on a live stream (QC-040).
 */
#[Fillable(['live_stream_id', 'customer_user_id', 'author_name', 'body', 'status', 'moderation_reason', 'moderated_by', 'moderated_at'])]
class LiveStreamComment extends Model
{
    use UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => StreamCommentStatus::class, 'moderated_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<LiveStream, $this>
     */
    public function stream(): BelongsTo
    {
        return $this->belongsTo(LiveStream::class, 'live_stream_id');
    }
}
