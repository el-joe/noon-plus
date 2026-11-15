<?php

namespace App\Domains\Content\Enums;

/**
 * Page / blog post schedule (SRS §15, lifecycle catalogue §9.2): draft →
 * published | scheduled; scheduled → published (PageSchedulerJob every
 * 5 min) | draft; published → archived | draft (unpublish); archived → draft.
 */
enum PageStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Archived = 'archived';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Published, self::Scheduled, self::Archived],
            self::Scheduled => [self::Published, self::Draft],
            self::Published => [self::Archived, self::Draft],
            self::Archived => [self::Draft],
        };
    }
}
