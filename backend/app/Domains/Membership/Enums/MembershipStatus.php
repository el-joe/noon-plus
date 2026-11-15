<?php

namespace App\Domains\Membership\Enums;

/**
 * Nawy One membership (lifecycle catalogue §3.6 pattern, appendix A):
 * incomplete (first payment pending) → active; trialing → active;
 * active → active (renewed) | past_due | cancelled (auto-renew off at period end);
 * past_due → active | cancelled | expired (grace over). A cancelled or
 * expired member can subscribe again.
 */
enum MembershipStatus: string
{
    case Incomplete = 'incomplete';
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Incomplete => [self::Active, self::Trialing, self::Incomplete, self::Expired],
            self::Trialing => [self::Active, self::PastDue, self::Cancelled],
            self::Active => [self::Active, self::PastDue, self::Cancelled],
            self::PastDue => [self::Active, self::Cancelled, self::Expired],
            self::Cancelled, self::Expired => [self::Incomplete, self::Trialing],
        };
    }
}
