<?php

namespace App\Shared\Http;

/**
 * User search text for SQL LIKE (QC-071): `%`, `_` and `\` match literally.
 */
final class LikeSearch
{
    public static function contains(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%';
    }
}
