<?php

namespace App\Domains\Refunds\Enums;

/**
 * Where a refund goes (SRS §7.5): back to the original payment method (card
 * 5–10 days) or to Nawy Credits (instant). COD and Nawy Credits payments
 * always come back as credits.
 */
enum RefundDestination: string
{
    case Original = 'original';
    case Wallet = 'wallet';
}
