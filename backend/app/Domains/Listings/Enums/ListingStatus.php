<?php

namespace App\Domains\Listings\Enums;

/**
 * Listing lifecycle (SRS §5.2):
 * draft → pending_review → active ⇄ paused / out_of_stock; rejected; archived.
 */
enum ListingStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Active = 'active';
    case Paused = 'paused';
    case OutOfStock = 'out_of_stock';
    case Rejected = 'rejected';
    case Archived = 'archived';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::PendingReview, self::Archived],
            self::PendingReview => [self::Active, self::Rejected],
            self::Rejected => [self::PendingReview, self::Archived],
            self::Active => [self::Paused, self::OutOfStock, self::Archived],
            self::Paused => [self::Active, self::Archived],
            self::OutOfStock => [self::Active, self::Archived],
            self::Archived => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function isSellable(): bool
    {
        return $this === self::Active;
    }
}
