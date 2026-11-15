<?php

namespace App\Domains\Documents\Enums;

/**
 * Onboarding document / product certification (lifecycle catalogue §1.4,
 * §7.2): pending → approved | rejected; approved → expired; a new upload
 * starts again as pending.
 */
enum DocumentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Rejected],
            self::Approved => [self::Expired],
            self::Rejected, self::Expired => [],
        };
    }
}
