<?php

namespace App\Domains\Returns\Enums;

enum ReturnMethod: string
{
    case Pickup = 'pickup';
    case DropOff = 'drop_off';
}
