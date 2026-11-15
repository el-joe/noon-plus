<?php

namespace App\Domains\LiveMedia\Enums;

/**
 * Stream (SRS §14, lifecycle catalogue §9.4). Live broadcast: scheduled |
 * idle → live (Go Live) → ended (final, FR-LS-4). Recorded channel:
 * scheduled → live → ended on its own clock; an ended channel re-timed to
 * the future heals back to scheduled (FR-LS-8).
 */
enum StreamStatus: string
{
    case Scheduled = 'scheduled';
    case Idle = 'idle';
    case Live = 'live';
    case Ended = 'ended';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Scheduled => [self::Live, self::Idle, self::Ended],
            self::Idle => [self::Live, self::Scheduled, self::Ended],
            self::Live => [self::Ended],
            self::Ended => [self::Scheduled],
        };
    }
}
