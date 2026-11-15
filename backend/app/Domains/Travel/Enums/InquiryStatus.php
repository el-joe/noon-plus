<?php

namespace App\Domains\Travel\Enums;

/**
 * Travel inquiry: new → contacted → converted | closed.
 */
enum InquiryStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Converted = 'converted';
    case Closed = 'closed';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::New => [self::Contacted, self::Converted, self::Closed],
            self::Contacted => [self::Converted, self::Closed],
            default => [],
        };
    }
}
