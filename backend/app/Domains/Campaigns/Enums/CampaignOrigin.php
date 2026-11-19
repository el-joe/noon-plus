<?php

namespace App\Domains\Campaigns\Enums;

/**
 * Campaign origins (SRS §11.3). F (affiliate/broker joins an open campaign)
 * is an invitation source, not a campaign.
 */
enum CampaignOrigin: string
{
    case Vendor = 'vendor';                  // A
    case TravelAgency = 'travel_agency';     // B — closed by owner decision D11 (travel runs no campaigns)
    case Platform = 'platform';              // C
    case MarketerRequest = 'marketer_request'; // D
    case Peer = 'peer';                      // E

    public function letter(): string
    {
        return match ($this) {
            self::Vendor => 'A', self::TravelAgency => 'B', self::Platform => 'C', self::MarketerRequest => 'D', self::Peer => 'E',
        };
    }
}
