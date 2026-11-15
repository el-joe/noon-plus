<?php

namespace App\Shared\Http;

use Illuminate\Support\Facades\Storage;

/**
 * A full public URL for a stored file path (G-W14/G-W18/G-W26): null stays
 * null, an absolute URL (seeded or external image) is kept, a storage path
 * becomes the disk's public URL.
 */
class PublicUrl
{
    public static function of(?string $path, string $disk = 'public'): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return Storage::disk($disk)->url($path);
    }
}
