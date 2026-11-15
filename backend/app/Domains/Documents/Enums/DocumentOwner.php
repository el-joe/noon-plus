<?php

namespace App\Domains\Documents\Enums;

/**
 * Business types documents are required for (SRS §13.7). `certification`
 * types are product certifications per category (ListingCertificationGate).
 */
enum DocumentOwner: string
{
    case Vendor = 'vendor';
    case Marketer = 'marketer';
    case Agency = 'agency';
    case DeliveryAgent = 'delivery_agent';
    case Classified = 'classified';
    case Broker = 'broker';
    case Carrier = 'carrier';
    case Certification = 'certification';
}
