<?php

namespace App\Domains\Advertising\Enums;

/**
 * Self-serve ad campaign (SRS §10, lifecycle catalogue §4.7).
 */
enum AdCampaignStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Active = 'active';
    case Paused = 'paused';
    case BudgetExhausted = 'budget_exhausted';
    case Ended = 'ended';
    case Rejected = 'rejected';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::PendingReview],
            self::PendingReview => [self::Active, self::Rejected],
            self::Active => [self::Paused, self::BudgetExhausted, self::Ended],
            self::Paused => [self::Active, self::Ended],
            self::BudgetExhausted => [self::Active, self::Ended, self::Paused],
            self::Ended, self::Rejected => [],
        };
    }
}
