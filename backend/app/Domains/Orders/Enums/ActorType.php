<?php

namespace App\Domains\Orders\Enums;

enum ActorType: string
{
    case Customer = 'customer';
    case Vendor = 'vendor';
    case Admin = 'admin';
    case System = 'system';
}
