<?php

namespace App\Domains\Panels\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Identity\Enums\AdminPermission;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Identity\Support\AdminAccess;
use App\Models\User;

/**
 * Bell notifications for the admins of the current country who hold a
 * permission (super admins included), e.g. "a new product request is
 * waiting". At most 50 admins are told; the queue page is the source of truth.
 */
class AdminAlerts
{
    public function __construct(private PanelNotifications $notifications, private AdminAccess $access, private CountryContext $countryContext) {}

    public function notify(AdminPermission $permission, string $type, string $title, ?string $body, string $link): int
    {
        $countryId = $this->countryContext->has() ? $this->countryContext->current()->id : null;
        $told = 0;

        User::query()
            ->whereHas('memberships', fn ($query) => $query->where('role', MembershipRole::Admin)->where('status', 'active')
                ->where(fn ($scope) => $scope->whereNull('country_id')->when($countryId !== null, fn ($q) => $q->orWhere('country_id', $countryId))))
            ->orderBy('id')->limit(50)->get()
            ->each(function (User $admin) use ($permission, $type, $title, $body, $link, &$told): void {
                if ($this->access->allows($admin, $permission)) {
                    $this->notifications->notify($admin->id, 'admin', $type, $title, $body, $link);
                    $told++;
                }
            });

        return $told;
    }
}
