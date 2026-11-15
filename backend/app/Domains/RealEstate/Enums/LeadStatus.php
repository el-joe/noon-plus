<?php

namespace App\Domains\RealEstate\Enums;

/**
 * Real-estate lead pipeline (spec §5.7, lifecycle catalogue Appendix A):
 * new → contacted → viewing_scheduled → negotiating → won | lost.
 */
enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case ViewingScheduled = 'viewing_scheduled';
    case Negotiating = 'negotiating';
    case Won = 'won';
    case Lost = 'lost';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::New => [self::Contacted, self::ViewingScheduled, self::Lost],
            self::Contacted => [self::ViewingScheduled, self::Negotiating, self::Lost],
            self::ViewingScheduled => [self::Contacted, self::Negotiating, self::Lost],
            self::Negotiating => [self::Won, self::Lost],
            self::Won, self::Lost => [],
        };
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Won, self::Lost], true);
    }
}
