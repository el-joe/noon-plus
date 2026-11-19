<?php

namespace App\Domains\Support\Enums;

use App\Domains\Orders\Enums\ActorType;

/**
 * Who opened a ticket (lifecycle catalogue §9.3: customer / vendor /
 * marketer / agency / carrier). Business requesters share their tenant's
 * tickets with their team.
 */
enum TicketRequesterType: string
{
    case Customer = 'customer';
    case Vendor = 'vendor';
    case Marketer = 'marketer';
    case TravelAgency = 'travel_agency';
    case Carrier = 'carrier';

    public function actor(): ActorType
    {
        return match ($this) {
            self::Customer => ActorType::Customer,
            self::Vendor, self::TravelAgency => ActorType::Vendor,
            self::Marketer => ActorType::Marketer,
            self::Carrier => ActorType::Carrier,
        };
    }
}
