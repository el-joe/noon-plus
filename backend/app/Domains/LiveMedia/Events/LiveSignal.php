<?php

namespace App\Domains\LiveMedia\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * D18: one WebRTC signalling message, relayed by the API (never a client
 * event) to a private channel — the host studio's `live.{slug}.host` or a
 * viewer's `live.{slug}.peer.{peer}`. Pusher event name `signal`; the
 * payload carries `type` (ready | answer | offer | candidate | leave |
 * online | ended), the SDP or ICE candidate and, towards the host, the
 * sending `peer`. Sent at once (not queued): signalling is latency bound.
 * No personal data: peers are random ids.
 */
class LiveSignal implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public string $channel, public array $payload) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->channel)];
    }

    public function broadcastAs(): string
    {
        return 'signal';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
