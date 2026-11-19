<?php

namespace App\Shared\Http;

use App\Domains\Countries\Support\CountryContext;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Short server-side cache for small public, non-personalised API answers
 * (countries, market context, brands, help collections, static pages, blog
 * categories, app contexts). Answers live under a group version: a change
 * to any row behind a group bumps the version ({@see flush()}), so the next
 * request rebuilds at once; the TTL only bounds counters that move without
 * a model event (product counts, article views).
 *
 * Groups are per country (`help:uae`) unless the data is central
 * (`countries`, `static-pages`).
 */
class PublicCache
{
    public const COUNTRIES = 'countries';

    public const BRANDS = 'brands';

    /** Header mega menu (categories, brands; product counts within the TTL). */
    public const CATALOG_MENU = 'catalog-menu';

    public const HELP = 'help';

    public const STATIC_PAGES = 'static-pages';

    public const BLOG_CATEGORIES = 'blog-categories';

    public const APP_CONTEXTS = 'app-contexts';

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $build
     * @return TValue
     */
    public static function remember(string $group, string $key, int $seconds, Closure $build): mixed
    {
        return Cache::remember('public-cache:'.$group.':'.self::version($group).':'.$key, $seconds, $build);
    }

    /**
     * The group of the current country (`help:uae`).
     */
    public static function countryGroup(string $group, ?string $countryCode = null): string
    {
        return $group.':'.($countryCode ?? app(CountryContext::class)->current()->code);
    }

    /**
     * Forget every cached answer of a group.
     */
    public static function flush(string $group): void
    {
        Cache::forever('public-cache:'.$group.':version', Str::random(12));
    }

    /**
     * Forget a per-country group in the current country (a country-database model changed).
     */
    public static function flushCountry(string $group): void
    {
        $context = app(CountryContext::class);

        if ($context->has()) {
            self::flush(self::countryGroup($group, $context->current()->code));
        }
    }

    private static function version(string $group): string
    {
        return (string) Cache::rememberForever('public-cache:'.$group.':version', fn (): string => Str::random(12));
    }
}
