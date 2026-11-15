<?php

namespace App\Domains\Vendors\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Panels\Support\PanelCountries;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;

/**
 * The store a Seller Center request works for (role-permission matrix §4 T4):
 * the vendor named by the user's active `vendor:product` or (WP6)
 * `vendor:classified` membership in the
 * current country (tenant_type "vendor", tenant_id = vendors.id in that
 * country's database). Every seller query is scoped to {@see Vendor()}.
 */
class VendorWorkspace
{
    private ?Vendor $vendor = null;

    /**
     * Bind the request to the user's store in the current country, if any.
     */
    public function resolve(User $user, CountryContext $countryContext): ?Vendor
    {
        $this->vendor = null;

        if (! $countryContext->has()) {
            return null;
        }

        $vendorId = $this->vendorIdFor($user, $countryContext);

        $this->vendor = $vendorId === null ? null : Vendor::query()->find($vendorId);

        return $this->vendor;
    }

    public function has(): bool
    {
        return $this->vendor !== null;
    }

    public function vendor(): Vendor
    {
        abort_if($this->vendor === null, 403, __('Your account is not linked to a store in this country.'));

        return $this->vendor;
    }

    /**
     * Whether the user may act for this vendor in the current country.
     */
    public function userOwns(User $user, int $vendorId): bool
    {
        $countryContext = app(CountryContext::class);

        return $countryContext->has() && $this->vendorIdFor($user, $countryContext) === $vendorId;
    }

    private function vendorIdFor(User $user, CountryContext $countryContext): ?int
    {
        $tenantId = $user->memberships()
            ->whereIn('role', [MembershipRole::ProductVendor, MembershipRole::ClassifiedSeller])
            ->where('status', 'active')
            ->where('country_id', $countryContext->current()->id)
            ->where('tenant_type', PanelCountries::VENDOR_TENANT)
            ->value('tenant_id');

        return $tenantId === null ? null : (int) $tenantId;
    }
}
