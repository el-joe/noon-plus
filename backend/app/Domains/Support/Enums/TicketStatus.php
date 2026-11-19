<?php

namespace App\Domains\Support\Enums;

/**
 * Support ticket lifecycle (SRS §15, lifecycle catalogue §9.3):
 * open → in_progress ⇄ waiting_customer → resolved → closed (T);
 * resolved → open when the requester reopens within the reopen window.
 * Support may also resolve a ticket that waits on the requester.
 */
enum TicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case WaitingCustomer = 'waiting_customer';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Open => [self::InProgress],
            self::InProgress => [self::WaitingCustomer, self::Resolved],
            self::WaitingCustomer => [self::InProgress, self::Resolved],
            self::Resolved => [self::Closed, self::Open],
            self::Closed => [],
        };
    }

    /**
     * Still being worked on (counts towards the SLA).
     *
     * @return array<int, string>
     */
    public static function activeValues(): array
    {
        return [self::Open->value, self::InProgress->value, self::WaitingCustomer->value];
    }
}
