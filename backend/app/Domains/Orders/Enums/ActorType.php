<?php

namespace App\Domains\Orders\Enums;

enum ActorType: string
{
    case Customer = 'customer';
    case Vendor = 'vendor';
    case Admin = 'admin';
    case System = 'system';
    case Marketer = 'marketer';
    case Agent = 'agent';
    case Carrier = 'carrier';
    case Applicant = 'applicant';
}
