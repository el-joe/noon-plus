<?php

namespace App\Domains\Refunds\Enums;

enum RefundSource: string
{
    case Return = 'return';
    case Dispute = 'dispute';
    case WarrantyClaim = 'warranty_claim';
    case Cancellation = 'cancellation';
    case Admin = 'admin';
}
