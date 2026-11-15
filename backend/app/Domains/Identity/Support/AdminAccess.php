<?php

namespace App\Domains\Identity\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Identity\Enums\AdminPermission;
use App\Domains\Identity\Enums\MembershipRole;
use App\Models\User;

/**
 * Admin authorization (role-permission matrix §3): a super admin (active admin
 * membership without a country) may do everything; any other admin needs an
 * active admin membership in the current country and the permission through
 * a Spatie role in that country (team = country). Restrictions (account
 * manager, matrix §3.3) are never held by a super admin.
 */
class AdminAccess
{
    public function __construct(private CountryContext $countryContext) {}

    public function allows(User $user, AdminPermission $permission): bool
    {
        if ($this->isSuperAdmin($user)) {
            return ! $permission->isRestriction();
        }

        if (! $this->countryContext->has() || ! $user->hasMembership(MembershipRole::Admin, $this->countryContext->current())) {
            return false;
        }

        setPermissionsTeamId($this->countryContext->current()->id);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return $user->checkPermissionTo($permission->value, 'web');
    }

    public function isSuperAdmin(User $user): bool
    {
        return $user->memberships()
            ->where('role', MembershipRole::Admin)
            ->where('status', 'active')
            ->whereNull('country_id')
            ->exists();
    }
}
