<?php

namespace App\Domains\Catalog\Enums;

/**
 * Product request (lifecycle catalogue §1.3, SRS §5.1 FR-CAT-2): a vendor
 * or marketer asks for a product that is not in the catalogue. pending →
 * approved (the product is created) | rejected (reason) | cancelled by the
 * requester.
 */
enum ProductRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Rejected, self::Cancelled],
            default => [],
        };
    }
}
