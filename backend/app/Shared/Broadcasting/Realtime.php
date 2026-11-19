<?php

namespace App\Shared\Broadcasting;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Throwable;

/**
 * D16: fire-and-forget broadcasts for the live UIs (Reverb). Clients keep
 * polling when no socket is open, so a broadcast that cannot be sent (Reverb
 * down, no keys in a local setup) is reported and never fails the request
 * that caused it.
 */
class Realtime
{
    public static function broadcast(ShouldBroadcast $event): void
    {
        if (in_array(config('broadcasting.default'), [null, 'null'], true)) {
            return;
        }

        try {
            event($event);
        } catch (Throwable $failed) {
            report($failed);
        }
    }
}
