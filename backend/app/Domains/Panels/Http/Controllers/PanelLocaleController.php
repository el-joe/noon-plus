<?php

namespace App\Domains\Panels\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Localization\SetApiLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Panel language switch (QC-028): saved on the account when signed in
 * (so emails follow it too), in the session on the sign-in pages.
 */
class PanelLocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $locale = $request->validate(['locale' => ['required', Rule::in(SetApiLocale::SUPPORTED)]])['locale'];

        $request->user()?->forceFill(['preferred_locale' => $locale])->save();
        $request->session()->put('locale', $locale);

        return back();
    }
}
