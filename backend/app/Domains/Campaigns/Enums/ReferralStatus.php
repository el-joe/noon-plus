<?php

namespace App\Domains\Campaigns\Enums;

/**
 * Referral code validity matrix (SRS §11.8, ReferralCodeResolver).
 */
enum ReferralStatus: string
{
    case Active = 'active';
    case Pending = 'pending';
    case Grace = 'grace';
    case Expired = 'expired';
    case CampaignClosed = 'campaign_closed';
    case Unknown = 'unknown';
}
