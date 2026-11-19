<?php

namespace App\Domains\Reviews\Enums;

/**
 * Review lifecycle (lifecycle catalogue §2.9, SRS §7.8; QC-012):
 * created `pending`, or `auto_flagged` by the keyword rules; an admin
 * publishes or rejects; a customer / vendor report moves a published review
 * to `flagged`; an admin then publishes it again, hides it or rejects it.
 * An admin may also hide a published review directly and restore a hidden
 * one. `published` and `flagged` reviews are public and count in the rating
 * aggregates — a report alone never takes a review down (a store must not
 * be able to hide criticism by reporting it); the moderator decides.
 */
enum ReviewStatus: string
{
    case Pending = 'pending';
    case Published = 'published';
    case Flagged = 'flagged';
    case AutoFlagged = 'auto_flagged';
    case Hidden = 'hidden';
    case Rejected = 'rejected';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Published, self::Rejected],
            self::Published => [self::Flagged, self::Hidden, self::Rejected],
            self::Flagged, self::AutoFlagged => [self::Published, self::Hidden, self::Rejected],
            self::Hidden => [self::Published],
            self::Rejected => [],
        };
    }

    /**
     * Shown on the site and counted in ratings.
     *
     * @return array<int, self>
     */
    public static function public(): array
    {
        return [self::Published, self::Flagged];
    }

    public function isPublic(): bool
    {
        return in_array($this, self::public(), true);
    }

    /**
     * Waiting for a moderator.
     *
     * @return array<int, self>
     */
    public static function queue(): array
    {
        return [self::Pending, self::Flagged, self::AutoFlagged];
    }
}
