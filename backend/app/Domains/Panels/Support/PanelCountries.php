<?php

namespace App\Domains\Panels\Support;

use App\Domains\Countries\Models\Country;
use App\Domains\Identity\Enums\MembershipRole;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The countries a user may work in on a panel (role-permission matrix §1, §3.4):
 * an admin membership without a country covers every active country; any
 * other membership covers its own country only. On the partner panel only
 * product-vendor memberships bound to a vendor (tenant) count.
 */
class PanelCountries
{
    public const VENDOR_TENANT = 'vendor';

    /**
     * @return Collection<int, Country>
     */
    public function for(User $user, Panel $panel): Collection
    {
        $memberships = $user->memberships()
            ->where('status', 'active')
            ->whereIn('role', array_map(fn (MembershipRole $role): string => $role->value, $panel->roles))
            ->when($panel->key === 'partner', fn ($query) => $query
                ->where('role', MembershipRole::ProductVendor)
                ->where('tenant_type', self::VENDOR_TENANT)
                ->whereNotNull('tenant_id')
                ->whereNotNull('country_id'))
            ->get(['country_id']);

        if ($memberships->isEmpty()) {
            return collect();
        }

        $countries = Country::query()->active();

        if ($panel->key !== 'admin' || $memberships->every(fn ($membership): bool => $membership->country_id !== null)) {
            $countries->whereIn('id', $memberships->pluck('country_id')->filter()->unique());
        }

        return $countries->get();
    }
}
