<?php

namespace App\Domains\Classifieds\Enums;

/**
 * Paid owner upsells (MP v2 §14b): featured (top of results), boost (bumped
 * in the recommended order for the period) and republish (bumped now and
 * the expiry extended by the category duration).
 */
enum PackageType: string
{
    case Featured = 'featured';
    case Boost = 'boost';
    case Republish = 'republish';
}
