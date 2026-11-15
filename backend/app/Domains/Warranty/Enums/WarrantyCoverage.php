<?php

namespace App\Domains\Warranty\Enums;

/**
 * Standard warranty is the vendor's; an extended plan bought at checkout is
 * covered by the platform (SRS §7.7).
 */
enum WarrantyCoverage: string
{
    case Standard = 'standard';
    case Extended = 'extended';
}
