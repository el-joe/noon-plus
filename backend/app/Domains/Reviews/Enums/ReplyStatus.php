<?php

namespace App\Domains\Reviews\Enums;

/**
 * Vendor reply to a review (lifecycle §2.9; QC-012): `pending → published /
 * rejected` by an admin; a published reply can be taken down (→ rejected);
 * the vendor may rewrite a rejected reply (→ pending again).
 */
enum ReplyStatus: string
{
    case Pending = 'pending';
    case Published = 'published';
    case Rejected = 'rejected';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Published, self::Rejected],
            self::Published => [self::Rejected],
            self::Rejected => [self::Pending],
        };
    }
}
