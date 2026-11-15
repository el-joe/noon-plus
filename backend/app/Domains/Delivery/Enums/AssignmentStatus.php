<?php

namespace App\Domains\Delivery\Enums;

/**
 * Delivery assignment (SRS §4.6, §8; lifecycle catalogue §2.4; ADR 15):
 * assigned → accepted | declined; accepted → picked_up; picked_up →
 * delivered (codes match) | failed (reason); assigned | accepted →
 * reassigned (a new assignment with a new code).
 */
enum AssignmentStatus: string
{
    case Assigned = 'assigned';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case PickedUp = 'picked_up';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Reassigned = 'reassigned';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Assigned => [self::Accepted, self::Declined, self::Reassigned, self::Cancelled],
            self::Accepted => [self::PickedUp, self::Reassigned, self::Cancelled],
            self::PickedUp => [self::Delivered, self::Failed, self::Cancelled],
            self::Declined, self::Delivered, self::Failed, self::Reassigned, self::Cancelled => [],
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }

    /**
     * @return array<int, self>
     */
    public static function open(): array
    {
        return [self::Assigned, self::Accepted, self::PickedUp];
    }
}
