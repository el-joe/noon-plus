<?php

namespace App\Domains\LiveMedia\Enums;

/**
 * A live comment (QC-040): visible to all, held by the word / link filter
 * until an admin shows it, or hidden by an admin.
 */
enum StreamCommentStatus: string
{
    case Visible = 'visible';
    case Held = 'held';
    case Hidden = 'hidden';
}
