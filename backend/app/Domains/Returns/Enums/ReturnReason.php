<?php

namespace App\Domains\Returns\Enums;

/**
 * Why the customer returns items (return wizard). Fault reasons are the only
 * ones allowed for custom / made-to-order items (settings catalogue
 * `return.excluded_custom_items`).
 */
enum ReturnReason: string
{
    case Defective = 'defective';
    case DamagedOnArrival = 'damaged_on_arrival';
    case WrongItem = 'wrong_item';
    case NotAsDescribed = 'not_as_described';
    case ChangedMind = 'changed_mind';
    case SizeOrFit = 'size_or_fit';
    case Other = 'other';

    public function isSellerFault(): bool
    {
        return in_array($this, [self::Defective, self::DamagedOnArrival, self::WrongItem, self::NotAsDescribed], true);
    }
}
