<?php

namespace App\Domains\Customers\Enums;

/**
 * Customer account (lifecycle catalogue Appendix A, SRS §4.1.5; QC-016):
 * active ⇄ suspended, and banned (terminal). `users.status`; only `active`
 * accounts sign in, and suspending or banning signs every device out.
 */
enum CustomerAccountStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Banned = 'banned';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Active => [self::Suspended, self::Banned],
            self::Suspended => [self::Active, self::Banned],
            self::Banned => [],
        };
    }
}
