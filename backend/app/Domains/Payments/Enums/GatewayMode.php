<?php

namespace App\Domains\Payments\Enums;

/**
 * Gateway environment per country (settings catalogue §5); sandbox until the
 * real keys arrive (risk R4).
 */
enum GatewayMode: string
{
    case Sandbox = 'sandbox';
    case Live = 'live';
}
