<?php

namespace App\Domains\Campaigns\Enums;

enum ContentStatus: string
{
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return $this === self::Submitted ? [self::Approved, self::Rejected] : [];
    }
}
