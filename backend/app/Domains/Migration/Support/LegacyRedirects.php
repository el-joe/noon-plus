<?php

namespace App\Domains\Migration\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reads the 301 map (`legacy_redirects`, built by `legacy:redirects`) for
 * the public lookup endpoint (the website asks for paths that would 404)
 * and the panel fallback (old portal paths that reach Laravel). Each answer,
 * found or not, is cached per path under a version that RedirectMap::store()
 * bumps, so a rebuilt map is served at once and a miss costs one indexed
 * read per path per day.
 */
class LegacyRedirects
{
    private const VERSION_KEY = 'legacy-redirects:version';

    /**
     * @return array{from: string, to: string, status: int, source: string}|null
     */
    public function find(string $path, ?string $source = null): ?array
    {
        $path = self::normalise($path);

        if ($path === null) {
            return null;
        }

        $version = (string) Cache::rememberForever(self::VERSION_KEY, fn (): string => (string) Str::uuid());
        $entry = Cache::remember(
            'legacy-redirects:'.$version.':'.sha1($path),
            (int) config('nawy.legacy.redirects_cache_seconds', 86400),
            function () use ($path): array {
                $row = DB::table('legacy_redirects')->where('from_path', $path)->first(['from_path', 'to_path', 'status', 'source']);

                return $row === null ? [] : ['from' => $row->from_path, 'to' => $row->to_path, 'status' => (int) $row->status, 'source' => $row->source];
            },
        );

        if ($entry === [] || ($source !== null && $entry['source'] !== $source)) {
            return null;
        }

        return $entry;
    }

    /**
     * Drop every cached answer (after the map is rebuilt or edited).
     */
    public static function flush(): void
    {
        Cache::forever(self::VERSION_KEY, (string) Str::uuid());
    }

    /**
     * The stored form of a request path: decoded, one leading slash, no
     * trailing slash, no query. Null for anything that cannot be a stored path.
     */
    public static function normalise(string $path): ?string
    {
        $path = rawurldecode(Str::before($path, '?'));
        $path = '/'.trim($path, '/');

        if ($path === '/' || mb_strlen($path) > 255 || str_contains($path, '//') || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            return null;
        }

        return $path;
    }
}
