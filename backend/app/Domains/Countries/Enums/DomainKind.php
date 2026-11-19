<?php

namespace App\Domains\Countries\Enums;

/**
 * What a mapped hostname serves (QC-044, MASTER_PLAN §5): a storefront
 * market (country + language), a business panel (an alias that opens the
 * panel's canonical host, optionally on one country), the public portal, or
 * an API host (informational, for TLS / DNS bookkeeping).
 */
enum DomainKind: string
{
    case Storefront = 'storefront';
    case Panel = 'panel';
    case Portal = 'portal';
    case Api = 'api';
}
