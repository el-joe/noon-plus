<?php

namespace App\Domains\Marketers\Enums;

use App\Domains\Identity\Enums\MembershipRole;

/**
 * Marketer job types (SRS §11.1): influencer = one fixed amount per
 * campaign; affiliate = % × basis per sale; broker = fixed per item + %.
 */
enum MarketerJobType: string
{
    case Influencer = 'influencer';
    case Affiliate = 'affiliate';
    case Broker = 'broker';

    public function membershipRole(): MembershipRole
    {
        return match ($this) {
            self::Influencer => MembershipRole::Influencer,
            self::Affiliate => MembershipRole::Affiliate,
            self::Broker => MembershipRole::Broker,
        };
    }

    public function label(): string
    {
        return __(ucfirst($this->value));
    }
}
