<?php

namespace App\Domains\Fulfilment\Enums;

enum RemovalType: string
{
    /** Shipped back to the seller's address. */
    case ReturnToSeller = 'return_to_seller';

    /** Disposed of by the warehouse. */
    case Disposal = 'disposal';
}
