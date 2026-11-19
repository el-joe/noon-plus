<?php

namespace App\Shared\Localization;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel locale (QC-028): the signed-in person's saved preference
 * (`users.preferred_locale`), else the language picked on the sign-in
 * pages (session), else the browser's. Arabic renders right-to-left.
 */
class SetPanelLocale
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->preferred_locale
            ?? ($request->hasSession() ? $request->session()->get('locale') : null)
            ?? $request->getPreferredLanguage(SetApiLocale::SUPPORTED);

        app()->setLocale(in_array($locale, SetApiLocale::SUPPORTED, true) ? $locale : config('app.locale'));

        return $next($request);
    }
}
