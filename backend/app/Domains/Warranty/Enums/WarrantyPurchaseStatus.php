<?php

namespace App\Domains\Warranty\Enums;

/**
 * Extended warranty purchase (SRS §7.7): active from placement until its
 * expiry date (expiry job daily 03:00), or cancelled with the order item.
 */
enum WarrantyPurchaseStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
