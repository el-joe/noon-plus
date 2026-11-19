<?php

namespace App\Domains\Campaigns\Enums;

/**
 * Campaign lifecycle (SRS §11.5, lifecycle catalogue §4.1). `completed` is
 * the legacy terminal state kept for imported campaigns (QC-036): nothing
 * moves into it on Nawy (new campaigns end `done`), and it behaves like
 * `done` everywhere (terminal, not live).
 */
enum CampaignStatus: string
{
    case PendingAdmin = 'pending_admin';
    case MarketerRequested = 'marketer_requested';
    case Active = 'active';
    case AutoApproved = 'auto_approved';
    case Paused = 'paused';
    case Done = 'done';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';
    case Completed = 'completed';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::PendingAdmin => [self::Active, self::AutoApproved, self::Rejected, self::Cancelled],
            self::MarketerRequested => [self::Active, self::Rejected, self::Cancelled],
            self::Active, self::AutoApproved => [self::Paused, self::Done, self::Cancelled],
            self::Paused => [self::Active, self::Done, self::Cancelled],
            self::Done, self::Cancelled, self::Rejected, self::Completed => [],
        };
    }

    public function isLive(): bool
    {
        return in_array($this, [self::Active, self::AutoApproved], true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }
}
