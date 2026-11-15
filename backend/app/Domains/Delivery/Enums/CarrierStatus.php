<?php

namespace App\Domains\Delivery\Enums;

enum CarrierStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
