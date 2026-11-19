<?php

namespace App\Domains\LiveMedia\Support;

use App\Domains\LiveMedia\Enums\StreamCommentStatus;
use App\Domains\LiveMedia\Enums\StreamStatus;
use App\Domains\LiveMedia\Events\LiveStreamUpdated;
use App\Domains\LiveMedia\Models\LiveStream;
use App\Domains\LiveMedia\Models\LiveStreamComment;
use App\Domains\Reviews\Support\ReviewAutoFlag;
use App\Models\User;
use App\Shared\Broadcasting\Realtime;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Support\Facades\DB;

/**
 * Live comments and viewer counts (QC-040, SRS §14). Comments are open to
 * signed-in customers while the stream is live; the review word list and
 * link filter ({@see ReviewAutoFlag}) hold a comment for an admin. Viewers
 * send a heartbeat; a viewer counts as watching for {@see self::PRESENCE_SECONDS}.
 */
class LiveStreamEngagement
{
    public const PRESENCE_SECONDS = 60;

    public function __construct(private ReviewAutoFlag $filter) {}

    public function comment(LiveStream $stream, User $author, string $body): LiveStreamComment
    {
        if ($stream->status !== StreamStatus::Live) {
            throw new TransitionRefused(__('Comments are open while the stream is live.'));
        }

        $reason = $this->filter->reason($body);

        $comment = LiveStreamComment::query()->create([
            'live_stream_id' => $stream->id,
            'customer_user_id' => $author->id,
            'author_name' => mb_substr((string) str($author->name)->before(' ')->whenEmpty(fn () => str(__('Viewer'))), 0, 80),
            'body' => $body,
            'status' => $reason === null ? StreamCommentStatus::Visible : StreamCommentStatus::Held,
            'moderation_reason' => $reason,
        ]);

        if ($comment->status === StreamCommentStatus::Visible) {
            $this->broadcastComment($stream, $comment);
        }

        return $comment;
    }

    /**
     * D16: the public row of a comment, as `GET live-streams/{slug}/comments` lists it.
     *
     * @return array{id: int, author: string|null, body: string, created_at: string|null}
     */
    public static function publicRow(LiveStreamComment $comment): array
    {
        return ['id' => $comment->id, 'author' => $comment->author_name, 'body' => $comment->body, 'created_at' => $comment->created_at?->toIso8601String()];
    }

    /**
     * D16: tell the watch pages about a like (`likes.updated`).
     */
    public function broadcastLikes(LiveStream $stream): void
    {
        Realtime::broadcast(new LiveStreamUpdated($stream->slug, 'likes.updated', ['likes' => (int) $stream->likes_count]));
    }

    private function broadcastComment(LiveStream $stream, LiveStreamComment $comment): void
    {
        Realtime::broadcast(new LiveStreamUpdated($stream->slug, 'comment.posted', self::publicRow($comment)));
    }

    public function moderate(LiveStreamComment $comment, StreamCommentStatus $status, int $adminUserId, ?string $reason = null): LiveStreamComment
    {
        $comment->forceFill([
            'status' => $status,
            'moderation_reason' => $reason ?? $comment->moderation_reason,
            'moderated_by' => $adminUserId,
            'moderated_at' => now(),
        ])->save();

        if ($status === StreamCommentStatus::Visible && $comment->stream !== null) {
            $this->broadcastComment($comment->stream, $comment);
        }

        return $comment;
    }

    /**
     * Record that this viewer is watching; returns the current viewer count.
     * The stream's peak and unique viewer totals follow.
     */
    public function heartbeat(LiveStream $stream, string $viewerKey): int
    {
        if ($stream->status !== StreamStatus::Live) {
            return 0;
        }

        $hash = hash('sha256', $stream->id.'|'.$viewerKey);

        return DB::connection($stream->getConnectionName())->transaction(function () use ($stream, $hash): int {
            $existing = DB::connection($stream->getConnectionName())->table('live_stream_viewers')
                ->where('live_stream_id', $stream->id)->where('viewer_hash', $hash)->lockForUpdate()->first();

            if ($existing === null) {
                DB::connection($stream->getConnectionName())->table('live_stream_viewers')->insert([
                    'live_stream_id' => $stream->id, 'viewer_hash' => $hash, 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $stream->increment('total_viewers');
            } else {
                DB::connection($stream->getConnectionName())->table('live_stream_viewers')->where('id', $existing->id)->update(['last_seen_at' => now(), 'updated_at' => now()]);
            }

            $current = $this->currentViewers($stream);

            if ($current > (int) $stream->peak_viewers) {
                $stream->forceFill(['peak_viewers' => $current])->save();
            }

            return $current;
        });
    }

    public function currentViewers(LiveStream $stream): int
    {
        if ($stream->status !== StreamStatus::Live) {
            return 0;
        }

        return DB::connection($stream->getConnectionName())->table('live_stream_viewers')
            ->where('live_stream_id', $stream->id)->where('last_seen_at', '>=', now()->subSeconds(self::PRESENCE_SECONDS))->count();
    }
}
