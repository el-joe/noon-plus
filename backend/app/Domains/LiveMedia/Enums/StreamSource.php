<?php

namespace App\Domains\LiveMedia\Enums;

/**
 * `live` = admin broadcast from the studio; `recorded` = scheduled
 * audio/video channel ("radio channel", SRS §14.2).
 */
enum StreamSource: string
{
    case Live = 'live';
    case Recorded = 'recorded';
}
