<?php

namespace App\Domains\Classifieds\Enums;

/**
 * Classified inquiry (FR-CLS-4, lifecycle catalogue §5.4): new → contacted → closed.
 */
enum ThreadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Closed = 'closed';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::New => [self::Contacted, self::Closed],
            self::Contacted => [self::Closed],
            self::Closed => [],
        };
    }
}
