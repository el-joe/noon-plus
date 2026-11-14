<?php

namespace App\Broadcasting;

use App\Domains\Countries\Models\Country;
use App\Domains\Identity\Enums\MembershipRole;
use App\Models\User;

/**
 * Private real-time channel per country for live operations (dispatch map,
 * approvals). Admins see every country; carrier supervisors only their own.
 * Channel name: `country.{code}.ops`.
 */
class CountryOpsChannel
{
    public function join(User $user, string $code): bool
    {
        $country = Country::query()->where('code', $code)->where('is_active', true)->first();

        if ($country === null) {
            return false;
        }

        return $user->hasMembership(MembershipRole::Admin)
            || $user->hasMembership(MembershipRole::CarrierSupervisor, $country);
    }
}
