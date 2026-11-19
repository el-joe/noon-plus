<?php

namespace App\Shared\Localization;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API locale (QC-028; global middleware, `api/*` only, so unmatched routes too): `Accept-Language` picks English or Arabic for every
 * API request; on market routes ResolveMarket then sets the market's
 * language (`uae-ar` → ar), which wins. The response says which was used.
 */
class SetApiLocale
{
    public const SUPPORTED = ['en', 'ar'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/*')) {
            return $next($request);
        }

        app()->setLocale($request->getPreferredLanguage(self::SUPPORTED) ?? config('app.locale'));

        $response = $next($request);
        $response->headers->set('Content-Language', app()->getLocale());

        return $response;
    }
}
