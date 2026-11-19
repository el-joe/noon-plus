<?php

namespace App\Domains\Orders\Enums;

/**
 * Who made a state move (lifecycle catalogue §0): `agency` is a travel
 * agency's team (QC-031), `gateway` a verified payment webhook.
 */
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
    case Agency = 'agency';
    case Gateway = 'gateway';
}
