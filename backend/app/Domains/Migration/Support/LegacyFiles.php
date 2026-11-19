<?php

namespace App\Domains\Migration\Support;

use Illuminate\Support\Facades\Storage;

/**
 * The old platform's public and private disks, read only (plan §1 "Files"):
 * files are copied into the new storage — never moved — and their SHA-256
 * is checked after the copy. The root comes from `nawy.legacy.storage_root`.
 */
class LegacyFiles
{
    /**
     * Why the last read failed: missing, unreadable or checksum.
     */
    public ?string $error = null;

    public function root(): string
    {
        return rtrim((string) config('nawy.legacy.storage_root'), '/');
    }

    /**
     * The file's bytes, or null with {@see $error} set.
     */
    public function read(string $legacyDisk, string $path): ?string
    {
        $full = $this->path($legacyDisk, $path);
        $this->error = null;

        if ($full !== null && ! file_exists($full) && is_dir(dirname($full)) && ! is_readable(dirname($full))) {
            $this->error = 'unreadable';

            return null;
        }

        if ($full === null || ! file_exists($full)) {
            $this->error = 'missing';

            return null;
        }

        $bytes = is_readable($full) ? @file_get_contents($full) : false;

        if ($bytes === false) {
            $this->error = 'unreadable';

            return null;
        }

        return $bytes;
    }

    /**
     * Copy a legacy file to a new disk; true, or why not ('missing',
     * 'unreadable', 'checksum'). An identical copy already there is left alone.
     */
    public function copy(string $legacyDisk, string $path, string $targetDisk, string $targetPath, ?string $expectedSha256 = null): true|string
    {
        $bytes = $this->read($legacyDisk, $path);

        if ($bytes === null) {
            return (string) $this->error;
        }

        $hash = hash('sha256', $bytes);

        if ($expectedSha256 !== null && ! hash_equals($expectedSha256, $hash)) {
            return 'checksum';
        }

        $disk = Storage::disk($targetDisk);

        if (! $disk->exists($targetPath) || hash('sha256', (string) $disk->get($targetPath)) !== $hash) {
            $disk->put($targetPath, $bytes);
        }

        return hash('sha256', (string) $disk->get($targetPath)) === $hash ? true : 'checksum';
    }

    private function path(string $legacyDisk, string $path): ?string
    {
        $path = ltrim($path, '/');

        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        return $this->root().'/'.($legacyDisk === 'private' ? 'private' : 'public').'/'.$path;
    }
}
