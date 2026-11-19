<?php

namespace App\Domains\Vendors\Enums;

use App\Domains\Vendors\Support\VendorAccountStateMachine;

/**
 * Vendor account (lifecycle catalogue Appendix A, SRS §4.1.5; QC-016):
 * active ⇄ on_hold (account review; payouts held), active / on_hold ⇄
 * suspended, and blacklisted (terminal). Only `active` stores sell and
 * show. `vendors.status` stays a string; {@see VendorAccountStateMachine}
 * writes every change with a history row.
 */
enum VendorStatus: string
{
    case Active = 'active';
    case OnHold = 'on_hold';
    case Suspended = 'suspended';
    case Blacklisted = 'blacklisted';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Active => [self::OnHold, self::Suspended, self::Blacklisted],
            self::OnHold => [self::Active, self::Suspended, self::Blacklisted],
            self::Suspended => [self::Active, self::Blacklisted],
            self::Blacklisted => [],
        };
    }
}
