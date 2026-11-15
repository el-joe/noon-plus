<?php

namespace App\Domains\Disputes\Enums;

/**
 * Admin decision on a dispute (SRS §7.6). favor_customer and split refund
 * the customer; favor_seller and no_action do not.
 */
enum DisputeResolution: string
{
    case FavorCustomer = 'favor_customer';
    case FavorSeller = 'favor_seller';
    case Split = 'split';
    case NoAction = 'no_action';

    public function refundsCustomer(): bool
    {
        return in_array($this, [self::FavorCustomer, self::Split], true);
    }
}
