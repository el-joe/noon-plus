<?php

namespace App\Domains\Support\Enums;

/**
 * Ticket priority: sets the first-response and resolution SLA
 * (settings `support.first_response_hours` / `support.resolution_hours`).
 * Requesters pick low, normal or high; only support sets urgent.
 */
enum TicketPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    /**
     * @return array<int, string>
     */
    public static function requesterValues(): array
    {
        return [self::Low->value, self::Normal->value, self::High->value];
    }
}
