<?php

namespace App\Shared\Localization;

use Closure;

/**
 * Run code in another locale and restore the previous one afterwards
 * (QC-028): emails and notices are written in the recipient's language,
 * whoever triggers them.
 */
final class InLocale
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(?string $locale, Closure $callback): mixed
    {
        $previous = app()->getLocale();

        if ($locale === null || $locale === '' || $locale === $previous) {
            return $callback();
        }

        app()->setLocale($locale);

        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }
}
