<?php

namespace App\Domains\Fulfilment\Enums;

/**
 * Which warehouse stock counter a movement changes. Available = on hand −
 * reserved; damaged units are unsellable and kept apart.
 */
enum StockBucket: string
{
    case OnHand = 'on_hand';
    case Reserved = 'reserved';
    case Damaged = 'damaged';
}
