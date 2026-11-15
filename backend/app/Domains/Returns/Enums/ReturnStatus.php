<?php

namespace App\Domains\Returns\Enums;

/**
 * Return lifecycle (SRS §7.4, lifecycle catalogue §2.5):
 * requested → approved | rejected | cancelled;
 * approved → awaiting_pickup (pickup) | in_transit (drop-off) | received | cancelled;
 * awaiting_pickup → in_transit → received → inspecting → completed.
 */
enum ReturnStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case AwaitingPickup = 'awaiting_pickup';
    case InTransit = 'in_transit';
    case Received = 'received';
    case Inspecting = 'inspecting';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Requested => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved => [self::AwaitingPickup, self::InTransit, self::Received, self::Cancelled],
            self::AwaitingPickup => [self::InTransit],
            self::InTransit => [self::Received],
            self::Received => [self::Inspecting],
            self::Inspecting => [self::Completed],
            self::Rejected, self::Completed, self::Cancelled => [],
        };
    }

    /**
     * Still being handled — blocks auto-complete of the sub-order.
     */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Rejected, self::Completed, self::Cancelled], true);
    }

    /**
     * @return array<int, string>
     */
    public static function openValues(): array
    {
        return array_map(fn (self $status): string => $status->value, array_filter(self::cases(), fn (self $status): bool => $status->isOpen()));
    }
}
