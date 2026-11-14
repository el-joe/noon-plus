<?php

namespace App\Domains\Identity\Enums;

/**
 * Role memberships (MASTER_PLAN §2). A membership ties a user to a role,
 * usually in one country, sometimes inside a tenant (vendor, agency, carrier).
 */
enum MembershipRole: string
{
    case Admin = 'admin';
    case Customer = 'customer';
    case ProductVendor = 'vendor:product';
    case ClassifiedSeller = 'vendor:classified';
    case Influencer = 'marketer:influencer';
    case Affiliate = 'marketer:affiliate';
    case Broker = 'marketer:broker';
    case MarketingAgency = 'marketer:agency';
    case TravelAgency = 'travel_agency';
    case CarrierSupervisor = 'carrier_supervisor';
    case DeliveryAgent = 'delivery_agent';
}
