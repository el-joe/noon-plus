<?php

namespace App\Domains\Panels\Http\Controllers;

use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryClock;
use App\Domains\Panels\Navigation\PanelNavigation;
use App\Domains\Panels\Support\Panel;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(CountryClock $clock): View
    {
        $panel = Panel::find('admin');

        return view('panels.admin.dashboard', [
            'panel' => $panel,
            'navigation' => PanelNavigation::for($panel, 'admin.dashboard'),
            'countries' => Country::query()->active()->get(),
            'adminNow' => $clock->inAdminTime(now()),
        ]);
    }
}
