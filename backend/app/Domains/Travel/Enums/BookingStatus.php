<?php

namespace App\Domains\Travel\Enums;

/**
 * Travel booking request (SRS §12.2, owner decision WP6: no payment on
 * Nawy). Seats are taken when the request starts: an unsubmitted `held`
 * request keeps them for `travel.seat_hold_minutes`; a submitted
 * `requested` booking keeps them until the agency confirms or rejects it.
 * The agency settles payment with the customer outside Nawy; confirmed
 * trips complete nightly after the return date.
 */
enum BookingStatus: string
{
    case Held = 'held';
    case HoldExpired = 'hold_expired';
    case Requested = 'requested';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Held => [self::Requested, self::HoldExpired, self::Cancelled],
            self::Requested => [self::Confirmed, self::Rejected, self::Cancelled],
            self::Confirmed => [self::Completed, self::Cancelled],
            default => [],
        };
    }

    /**
     * Statuses whose seats count in `seats_reserved`.
     */
    public function holdsSeats(): bool
    {
        return in_array($this, [self::Held, self::Requested, self::Confirmed, self::Completed], true);
    }
}
