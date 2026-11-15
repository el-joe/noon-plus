<?php

namespace App\Domains\Disputes\Enums;

enum DisputeReason: string
{
    case NotReceived = 'not_received';
    case ItemDamaged = 'item_damaged';
    case NotAsDescribed = 'not_as_described';
    case MissingParts = 'missing_parts';
    case Other = 'other';
}
