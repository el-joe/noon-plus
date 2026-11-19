<?php

namespace App\Domains\Finance\Enums;

/**
 * Vendor subscription (lifecycle catalogue §3.6, SRS §9):
 * (subscribe) → trialing | active; trialing → active (trial over, period
 * billed) | past_due | cancelled; active → active (renewed) | past_due
 * (invoice unpaid after the grace days) | cancelled (vendor at period end,
 * or admin); past_due → active (paid) | cancelled | expired (grace over).
 * Cancelled and expired are terminal; the vendor can subscribe again.
 */
enum VendorSubscriptionStatus: string
{
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
            self::Trialing => [self::Active, self::PastDue, self::Cancelled],
            self::Active => [self::Active, self::PastDue, self::Cancelled],
            self::PastDue => [self::Active, self::Cancelled, self::Expired],
            self::Cancelled, self::Expired => [],
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::PastDue], true);
    }
}
