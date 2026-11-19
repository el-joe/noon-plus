<?php

namespace App\Domains\Fulfilment\Enums;

/**
 * Warehouse units held for an order item: reserved at checkout, released on
 * cancellation, dispatched when the parcel leaves the warehouse, returned
 * when a dispatched parcel comes back (cancelled in transit).
 */
enum ReservationStatus: string
{
    case Reserved = 'reserved';
    case Released = 'released';
    case Dispatched = 'dispatched';
    case Returned = 'returned';
}
