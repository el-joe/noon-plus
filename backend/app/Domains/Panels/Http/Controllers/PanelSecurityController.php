<?php

namespace App\Domains\Panels\Http\Controllers;

use App\Domains\Panels\Support\Panel;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * QC-044: the signed-in person's own sign-in history (every panel and app
 * sign-in, newest first), as the customer API offers in `me/login-history`.
 */
class PanelSecurityController extends Controller
{
    public function __invoke(Request $request, string $panel): View
    {
        return view('panels.shared.security', [
            'events' => $request->user()->loginEvents()->latest('created_at')->orderByDesc('id')->limit(50)->get(),
            'panelNames' => collect(Panel::all())->mapWithKeys(fn (Panel $item): array => [$item->key => $item->name])->all(),
        ]);
    }
}
