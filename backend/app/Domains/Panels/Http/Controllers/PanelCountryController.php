<?php

namespace App\Domains\Panels\Http\Controllers;

use App\Domains\Panels\Support\Panel;
use App\Domains\Panels\Support\PanelCountries;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Country switcher in the panel header: only countries the user holds a
 * membership in (role-permission matrix §1 rule 1, §3.4).
 */
class PanelCountryController extends Controller
{
    public function __invoke(Request $request, PanelCountries $panelCountries): RedirectResponse
    {
        $panel = Panel::find($request->route('panel'));
        $countries = $panelCountries->for($request->user(), $panel);

        $code = $request->validate([
            'country' => ['required', 'string', Rule::in($countries->pluck('code')->all())],
        ])['country'];

        $request->session()->put("panel_country.{$panel->key}", $countries->firstWhere('code', $code)->id);

        return redirect()->route($panel->route('dashboard'));
    }
}
