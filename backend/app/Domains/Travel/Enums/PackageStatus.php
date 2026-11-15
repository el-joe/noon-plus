<?php

namespace App\Domains\Travel\Enums;

/**
 * Travel package (SRS §12.2, lifecycle catalogue §5.2).
 */
enum PackageStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Active = 'active';
    case SoldOut = 'sold_out';
    case Expired = 'expired';
    case Rejected = 'rejected';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::PendingReview],
            self::PendingReview => [self::Active, self::Rejected, self::Draft],
            self::Active => [self::SoldOut, self::Expired],
            self::SoldOut => [self::Active, self::Expired],
            self::Rejected => [self::Draft],
            self::Expired => [],
        };
    }

    public function isBookable(): bool
    {
        return $this === self::Active;
    }
}
