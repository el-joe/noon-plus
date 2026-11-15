<?php

namespace App\Domains\Classifieds\Enums;

/**
 * Classified abuse report (lifecycle catalogue Appendix A): open → reviewing → actioned | dismissed.
 */
enum ReportStatus: string
{
    case Open = 'open';
    case Reviewing = 'reviewing';
    case Actioned = 'actioned';
    case Dismissed = 'dismissed';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Open => [self::Reviewing, self::Actioned, self::Dismissed],
            self::Reviewing => [self::Actioned, self::Dismissed],
            self::Actioned, self::Dismissed => [],
        };
    }
}
