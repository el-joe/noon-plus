<?php

namespace App\Domains\Finance\Enums;

/**
 * Promotional credit grant (lifecycle catalogue appendix A):
 * granted → partially_used → used, or expired at `expires_at`.
 */
enum PromotionalCreditStatus: string
{
    case Granted = 'granted';
    case PartiallyUsed = 'partially_used';
    case Used = 'used';
    case Expired = 'expired';
}
