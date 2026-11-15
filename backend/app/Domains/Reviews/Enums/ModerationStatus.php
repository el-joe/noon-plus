<?php

namespace App\Domains\Reviews\Enums;

/**
 * Moderation of customer content (reviews, questions, seller answers):
 * pending → approved | rejected; an admin may later hide an approved item
 * (→ rejected) or restore a rejected one (→ approved). Only approved items
 * are public and count in the rating aggregates.
 */
enum ModerationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Rejected],
            self::Approved => [self::Rejected],
            self::Rejected => [self::Approved],
        };
    }
}
