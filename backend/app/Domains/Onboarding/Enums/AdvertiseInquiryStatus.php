<?php

namespace App\Domains\Onboarding\Enums;

/**
 * Advertise request from the portal form (SRS §4.2 "Advertise Inquiry"):
 * new → contacted → converted | closed; new → closed.
 */
enum AdvertiseInquiryStatus: string
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
            self::New => [self::Contacted, self::Closed],
            self::Contacted => [self::Converted, self::Closed],
            self::Converted, self::Closed => [],
        };
    }
}
