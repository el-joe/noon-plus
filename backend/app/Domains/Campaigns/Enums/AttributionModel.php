<?php

namespace App\Domains\Campaigns\Enums;

/**
 * After direct credit, which recorded clicks earn the sale (MP §8, fixes
 * SRS gap 1): the latest, the earliest, or every clicked invitation in
 * equal shares.
 */
enum AttributionModel: string
{
    case LastClick = 'last_click';
    case FirstClick = 'first_click';
    case Linear = 'linear';
}
