<?php

namespace App\Domains\Delivery\Enums;

/**
 * Delivery agent wallet withdrawal: pending → approved → paid; pending |
 * approved → rejected (money back to the wallet); pending → cancelled by
 * the agent. Four-eyes: whoever approved cannot also mark it paid.
 */
enum AgentWithdrawalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved => [self::Paid, self::Rejected],
            self::Paid, self::Rejected, self::Cancelled => [],
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Approved], true);
    }
}
