<?php

namespace App\Domains\Panels\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Http\Middleware\ResolveCarrier;
use App\Domains\Delivery\Models\Carrier;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Marketers\Support\MarketerProfiles;
use App\Domains\Marketers\Support\MarketerWorkspace;
use App\Domains\Travel\Http\Middleware\ResolveTravelAgency;
use App\Domains\Vendors\Models\Vendor;
use App\Domains\Vendors\Support\VendorWorkspace;
use App\Models\User;

/**
 * Workspace of an ops app role (vendor, marketer, supervisor, travel
 * agency apps), resolved exactly like the matching web panel: the Seller
 * Center store ({@see VendorWorkspace}), the Creator Studio profile
 * (panel membership + {@see MarketerProfiles::forUser()}), the Shipping
 * company ({@see ResolveCarrier::carrierFor()}) and the Travel Partner
 * agency ({@see ResolveTravelAgency::agencyFor()}), in the current country.
 */
class OpsWorkspaces
{
    public const ROLES = ['vendor', 'marketer', 'supervisor', 'travel_agency'];

    public function __construct(
        private CountryContext $countryContext,
        private VendorWorkspace $vendors,
        private MarketerProfiles $marketers,
        private MarketerWorkspace $marketerWorkspace,
        private ResolveCarrier $carriers,
        private ResolveTravelAgency $agencies,
    ) {}

    /**
     * The tenant the user works for in this role, or null (no workspace).
     * Binds the panel workspaces (store, marketer) for the rest of the request.
     */
    public function resolve(User $user, string $role): Vendor|MarketerProfile|Carrier|null
    {
        if (! $this->countryContext->has()) {
            return null;
        }

        return match ($role) {
            'vendor' => $this->vendors->resolve($user, $this->countryContext),
            'marketer' => $this->marketer($user),
            'supervisor' => $this->carriers->carrierFor($user),
            'travel_agency' => $this->agencies->agencyFor($user),
            default => null,
        };
    }

    /**
     * @return array{role: string, id: int, name: string}
     */
    public function summary(string $role, Vendor|MarketerProfile|Carrier $workspace): array
    {
        return [
            'role' => $role,
            'id' => (int) $workspace->id,
            'name' => (string) match (true) {
                $workspace instanceof Vendor => $workspace->store_name,
                $workspace instanceof MarketerProfile => $workspace->display_name,
                default => $workspace->name,
            },
        ];
    }

    private function marketer(User $user): ?MarketerProfile
    {
        $marketer = Panel::find('marketer')->allows($user) ? $this->marketers->forUser($user) : null;
        $this->marketerWorkspace->set($marketer);

        return $marketer;
    }
}
