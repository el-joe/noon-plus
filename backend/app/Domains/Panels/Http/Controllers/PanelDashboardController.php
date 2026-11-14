<?php

namespace App\Domains\Panels\Http\Controllers;

use App\Domains\Panels\Navigation\PanelNavigation;
use App\Domains\Panels\Support\Panel;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Landing page of a business panel until its domain module ships.
 */
class PanelDashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $panel = Panel::find($request->route('panel'));

        return view('panels.dashboard', [
            'panel' => $panel,
            'navigation' => PanelNavigation::for($panel, $panel->route('dashboard')),
            'memberships' => $request->user()->memberships()->with('country')->where('status', 'active')->get(),
        ]);
    }
}
