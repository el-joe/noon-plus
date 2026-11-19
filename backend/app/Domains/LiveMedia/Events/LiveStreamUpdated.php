<?php

namespace App\Domains\LiveMedia\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * D16: live stream updates for the watch page on the public channel
 * `live-stream.{slug}`: `comment.posted` (a visible comment, the same row as
 * `GET live-streams/{slug}/comments`) and `likes.updated` (`{likes}`). Held
 * comments are never broadcast; the website keeps polling without a socket.
 */
class LiveStreamUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $broadcastQueue = 'broadcasts';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public string $slug, public string $name, public array $payload) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel("live-stream.{$this->slug}")];
    }

    public function broadcastAs(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
