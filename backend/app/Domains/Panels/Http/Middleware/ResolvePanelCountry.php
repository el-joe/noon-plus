<?php

namespace App\Domains\Panels\Http\Middleware;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Panels\Support\Panel;
use App\Domains\Panels\Support\PanelCountries;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Selects the country a panel request works in (ADR 2): a `?country=` code the
 * user may use, else the one kept in the session, else their first country.
 * Sets the {@see CountryContext} and shares the switcher data with views.
 * Users without any country (e.g. a membership not yet bound to a store) go
 * on without a country; pages that need one refuse them.
 */
class ResolvePanelCountry
{
    public function __construct(
        private CountryContext $countryContext,
        private PanelCountries $panelCountries,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $panelKey): Response
    {
        $panel = Panel::find($panelKey);
        $countries = $this->panelCountries->for($request->user(), $panel);
        $sessionKey = "panel_country.{$panel->key}";

        $requested = $request->query('country');
        $country = (is_string($requested) ? $countries->firstWhere('code', $requested) : null)
            ?? $countries->firstWhere('id', $request->session()->get($sessionKey))
            ?? $countries->first();

        if ($country !== null) {
            $request->session()->put($sessionKey, $country->id);
            $this->countryContext->set($country);
        }

        View::share('panelCountries', $countries);
        View::share('currentCountry', $country);

        return $next($request);
    }
}
