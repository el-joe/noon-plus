<?php

namespace App\Providers;

use App\Domains\Identity\Enums\MembershipRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        if ($alertEmail = config('nawy.ops_alert_email')) {
            Horizon::routeMailNotificationsTo($alertEmail);
        }
    }

    /**
     * QA-045: the gate applies in every environment, local included —
     * Horizon's default lets anyone in when APP_ENV=local.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(fn ($request): bool => Gate::check('viewHorizon', [$request->user()]));
    }

    /**
     * Register the Horizon gate: admins only.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user = null): bool => $user?->hasMembership(MembershipRole::Admin) ?? false);
    }
}
