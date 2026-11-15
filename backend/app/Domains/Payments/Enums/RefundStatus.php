<?php

namespace App\Domains\Payments\Enums;

enum RefundStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
}
