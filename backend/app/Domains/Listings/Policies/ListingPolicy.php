<?php

namespace App\Domains\Listings\Policies;

use App\Domains\Listings\Models\Listing;
use App\Domains\Vendors\Support\VendorWorkspace;
use App\Models\User;

/**
 * Seller access to listings (role-permission matrix §5.2 `listings`, §4 T4):
 * only the store's own listings, in the store's country.
 */
class ListingPolicy
{
    public function __construct(private VendorWorkspace $workspace) {}

    public function manage(User $user, Listing $listing): bool
    {
        return $this->workspace->userOwns($user, (int) $listing->vendor_id);
    }
}
