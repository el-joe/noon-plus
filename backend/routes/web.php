<?php

use App\Domains\Panels\Http\Controllers\AdminDashboardController;
use App\Domains\Panels\Http\Controllers\PanelDashboardController;
use App\Domains\Panels\Http\Controllers\PanelLoginController;
use App\Domains\Panels\Http\Controllers\TwoFactorChallengeController;
use App\Domains\Panels\Http\Middleware\EnsurePanelMembership;
use App\Domains\Panels\Support\Panel;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
| Business panels — {host}.{base} (MASTER_PLAN §5). Every panel shares the
| email + password sign-in; each one admits only its own membership roles.
*/
foreach (Panel::all() as $panel) {
    Route::domain($panel->host)
        ->name($panel->key.'.')
        ->group(function () use ($panel): void {
            Route::middleware('guest')->group(function () use ($panel): void {
                Route::get('login', [PanelLoginController::class, 'show'])->defaults('panel', $panel->key)->name('login');
                Route::post('login', [PanelLoginController::class, 'store'])->defaults('panel', $panel->key)->middleware('throttle:6,1')->name('login.store');
                Route::get('two-factor', [TwoFactorChallengeController::class, 'show'])->defaults('panel', $panel->key)->name('two-factor');
                Route::post('two-factor', [TwoFactorChallengeController::class, 'store'])->defaults('panel', $panel->key)->middleware('throttle:6,1')->name('two-factor.store');
            });

            Route::middleware(['auth', EnsurePanelMembership::class.':'.$panel->key])->group(function () use ($panel): void {
                Route::get('/', $panel->key === 'admin' ? AdminDashboardController::class : PanelDashboardController::class)
                    ->defaults('panel', $panel->key)
                    ->name('dashboard');
                Route::post('logout', [PanelLoginController::class, 'destroy'])->defaults('panel', $panel->key)->name('logout');
            });
        });
}
