<?php

namespace App\Domains\Delivery\Enums;

/**
 * What went wrong with the shipment (SRS §4.1.11: lost or damaged).
 */
enum ClaimType: string
{
    case Lost = 'lost';
    case Damaged = 'damaged';
}
