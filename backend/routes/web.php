<?php

use App\Domains\Campaigns\Http\Controllers\InvitationResponseController;
use App\Domains\Delivery\Http\Controllers\Carrier\CarrierPanelController;
use App\Domains\Delivery\Http\Controllers\Panel\DeliveryPanelController;
use App\Domains\Marketers\Http\Controllers\Studio\CreatorStudioController;
use App\Domains\Marketers\Http\Middleware\ResolveMarketerWorkspace;
use App\Domains\Panels\Http\Controllers\AdminDashboardController;
use App\Domains\Panels\Http\Controllers\PanelCountryController;
use App\Domains\Panels\Http\Controllers\PanelDashboardController;
use App\Domains\Panels\Http\Controllers\PanelLocaleController;
use App\Domains\Panels\Http\Controllers\PanelLoginController;
use App\Domains\Panels\Http\Controllers\PanelNotificationController;
use App\Domains\Panels\Http\Controllers\PanelPasswordResetController;
use App\Domains\Panels\Http\Controllers\PanelSecurityController;
use App\Domains\Panels\Http\Controllers\PasswordSetupController;
use App\Domains\Panels\Http\Controllers\SellerDashboardController;
use App\Domains\Panels\Http\Controllers\TeamInvitationController;
use App\Domains\Panels\Http\Controllers\TwoFactorChallengeController;
use App\Domains\Panels\Http\Middleware\EnsurePanelMembership;
use App\Domains\Panels\Http\Middleware\ResolvePanelCountry;
use App\Domains\Panels\Http\Middleware\ResolveVendorWorkspace;
use App\Domains\Panels\Http\Middleware\ScalarQueryParameters;
use App\Domains\Panels\Support\Panel;
use App\Domains\Travel\Http\Controllers\Panel\TravelPartnerController;
use App\Domains\Travel\Http\Middleware\ResolveTravelAgency;
use Illuminate\Support\Facades\Route;

// QC-076 / QC-067: no stock Laravel page; the API host sends visitors to the storefront.
Route::get('/', fn () => redirect()->away((string) config('nawy.payments.storefront_url')));

/*
| Business panels — {host}.{base} (MASTER_PLAN §5). Every panel shares the
| email + password sign-in; each one admits only its own membership roles.
*/
foreach (Panel::all() as $panel) {
    Route::domain($panel->host)
        ->middleware(ScalarQueryParameters::class)
        ->name($panel->key.'.')
        ->group(function () use ($panel): void {
            if ($panel->key === 'marketer') {
                // WhatsApp invitation answers (ADR 5): signed, expiring, no sign-in.
                Route::get('invitations/{country}/{invitation}/respond', [InvitationResponseController::class, 'show'])
                    ->whereNumber('invitation')->middleware('signed')->name('invitations.respond');
                Route::post('invitations/{country}/{invitation}/respond', [InvitationResponseController::class, 'store'])
                    ->whereNumber('invitation')->middleware(['signed', 'throttle:10,1'])->name('invitations.respond.store');
            }

            // QC-028: language switch (signed in: saved on the account; sign-in pages: session).
            Route::post('locale', PanelLocaleController::class)->defaults('panel', $panel->key)->middleware('throttle:30,1')->name('locale');

            // QA-002 / QA-003: signed links mailed to the account or the invitee.
            Route::get('password/setup/{user}', [PasswordSetupController::class, 'show'])->whereNumber('user')->defaults('panel', $panel->key)->middleware('signed')->name('password.setup');
            Route::post('password/setup/{user}', [PasswordSetupController::class, 'store'])->whereNumber('user')->defaults('panel', $panel->key)->middleware(['signed', 'throttle:10,1'])->name('password.setup.store');
            Route::get('team-invitations/{country}/{token}', [TeamInvitationController::class, 'show'])->where('token', '[A-Za-z0-9]{40,64}')->defaults('panel', $panel->key)->name('team-invitations.show');
            Route::post('team-invitations/{country}/{token}', [TeamInvitationController::class, 'accept'])->where('token', '[A-Za-z0-9]{40,64}')->defaults('panel', $panel->key)->middleware('throttle:10,1')->name('team-invitations.accept');

            Route::middleware('guest')->group(function () use ($panel): void {
                Route::get('login', [PanelLoginController::class, 'show'])->defaults('panel', $panel->key)->name('login');
                Route::post('login', [PanelLoginController::class, 'store'])->defaults('panel', $panel->key)->middleware('throttle:panel-login')->name('login.store');
                Route::get('two-factor', [TwoFactorChallengeController::class, 'show'])->defaults('panel', $panel->key)->name('two-factor');
                Route::post('two-factor', [TwoFactorChallengeController::class, 'store'])->defaults('panel', $panel->key)->middleware('throttle:6,1')->name('two-factor.store');

                // QC-006: forgot / reset password (two-step sign-in still applies afterwards).
                Route::get('forgot-password', [PanelPasswordResetController::class, 'create'])->defaults('panel', $panel->key)->name('password.request');
                Route::post('forgot-password', [PanelPasswordResetController::class, 'store'])->defaults('panel', $panel->key)->middleware('throttle:panel-password-reset')->name('password.email');
                Route::get('reset-password/{token}', [PanelPasswordResetController::class, 'edit'])->defaults('panel', $panel->key)->name('password.reset');
                Route::post('reset-password', [PanelPasswordResetController::class, 'update'])->defaults('panel', $panel->key)->middleware('throttle:password-reset-submit')->name('password.update');
            });

            $middleware = ['auth', EnsurePanelMembership::class.':'.$panel->key, ResolvePanelCountry::class.':'.$panel->key];

            if ($panel->key === 'partner') {
                $middleware[] = ResolveVendorWorkspace::class;
            }

            if ($panel->key === 'marketer') {
                $middleware[] = ResolveMarketerWorkspace::class;
            }

            if ($panel->key === 'travel_agency') {
                $middleware[] = ResolveTravelAgency::class;
            }

            Route::middleware($middleware)->group(function () use ($panel): void {
                Route::get('/', match ($panel->key) {
                    'admin' => AdminDashboardController::class,
                    'partner' => SellerDashboardController::class,
                    'marketer' => [CreatorStudioController::class, 'overview'],
                    'travel_agency' => [TravelPartnerController::class, 'dashboard'],
                    'delivery' => [DeliveryPanelController::class, 'dashboard'],
                    'carrier' => [CarrierPanelController::class, 'dashboard'],
                    default => PanelDashboardController::class,
                })
                    ->defaults('panel', $panel->key)
                    ->name('dashboard');
                Route::post('country', PanelCountryController::class)->defaults('panel', $panel->key)->name('country');
                Route::post('logout', [PanelLoginController::class, 'destroy'])->defaults('panel', $panel->key)->name('logout');

                // QC-041: notification bell; QC-044: the panel user's own sign-in history.
                Route::get('notifications', [PanelNotificationController::class, 'index'])->defaults('panel', $panel->key)->name('notifications');
                Route::post('notifications/read-all', [PanelNotificationController::class, 'readAll'])->defaults('panel', $panel->key)->name('notifications.read-all');
                Route::post('notifications/{notification}/read', [PanelNotificationController::class, 'read'])->whereNumber('notification')->defaults('panel', $panel->key)->name('notifications.read');
                Route::get('account/security', PanelSecurityController::class)->defaults('panel', $panel->key)->name('security');

                if (is_file($routes = base_path("routes/panels/{$panel->key}.php"))) {
                    require $routes;
                }
            });
        });
}
