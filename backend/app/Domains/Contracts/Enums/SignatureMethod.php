<?php

namespace App\Domains\Contracts\Enums;

/**
 * How consent was given (SRS §13.6): typed at registration, a click-wrap
 * click, or continued use after a notice-mode effective date.
 */
enum SignatureMethod: string
{
    case Registration = 'registration';
    case Click = 'click';
    case ContinuedUse = 'continued_use';
}
