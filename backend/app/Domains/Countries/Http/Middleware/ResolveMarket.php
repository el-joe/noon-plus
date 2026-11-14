<?php

namespace App\Domains\Countries\Http\Middleware;

use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Selects the country and locale from the `{market}` route parameter
 * (e.g. "uae-en", "omn-ar"). Unknown or inactive markets return 404.
 */
class ResolveMarket
{
    public function __construct(private CountryContext $countryContext) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $market = (string) $request->route('market');

        if (! preg_match('/^([a-z]{3})-([a-z]{2})$/', $market, $parts)) {
            abort(404);
        }

        [, $code, $locale] = $parts;

        $country = Country::query()->where('code', $code)->where('is_active', true)->first();

        if ($country === null || ! in_array($locale, $country->locales, true)) {
            abort(404);
        }

        $this->countryContext->set($country);
        app()->setLocale($locale);
        $request->route()->forgetParameter('market');
        $request->attributes->set('market', $market);

        return $next($request);
    }
}
