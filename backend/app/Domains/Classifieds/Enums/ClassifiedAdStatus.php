<?php

namespace App\Domains\Classifieds\Enums;

/**
 * Classified ad lifecycle (SRS §12.1, lifecycle catalogue §5.1). The
 * contract is signed before submission (FR-CLS-1) and an ad is never public
 * before admin approval (FR-CLS-2).
 */
enum ClassifiedAdStatus: string
{
    case Draft = 'draft';
    case PendingContract = 'pending_contract';
    case PendingReview = 'pending_review';
    case Active = 'active';
    case Paused = 'paused';
    case Sold = 'sold';
    case Expired = 'expired';
    case Rejected = 'rejected';
    case Archived = 'archived';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::PendingContract, self::PendingReview, self::Archived],
            self::PendingContract => [self::PendingReview, self::Draft, self::Archived],
            self::PendingReview => [self::Active, self::Rejected, self::Draft],
            self::Active => [self::Paused, self::Sold, self::Expired, self::Archived],
            self::Paused => [self::Active, self::Expired, self::Archived],
            self::Sold => [self::Active, self::Archived],
            self::Expired => [self::Active, self::PendingReview, self::Archived],
            self::Rejected => [self::Archived],
            self::Archived => [],
        };
    }

    public function isPublic(): bool
    {
        return $this === self::Active;
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::PendingContract, self::Rejected], true);
    }
}
