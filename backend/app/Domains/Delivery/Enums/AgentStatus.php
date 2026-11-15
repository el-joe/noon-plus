<?php

namespace App\Domains\Delivery\Enums;

/**
 * Delivery agent (lifecycle catalogue Appendix A): pending_documents →
 * active ⇄ unavailable; active | unavailable → suspended → active.
 */
enum AgentStatus: string
{
    case PendingDocuments = 'pending_documents';
    case Active = 'active';
    case Unavailable = 'unavailable';
    case Suspended = 'suspended';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::PendingDocuments => [self::Active, self::Suspended],
            self::Active => [self::Unavailable, self::Suspended],
            self::Unavailable => [self::Active, self::Suspended],
            self::Suspended => [self::Active],
        };
    }
}
