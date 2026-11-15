<?php

namespace App\Domains\Orders\Policies;

use App\Domains\Orders\Models\SubOrder;
use App\Domains\Vendors\Support\VendorWorkspace;
use App\Models\User;

/**
 * Seller access to sub-orders (role-permission matrix §5.2 `orders`, §4 T4):
 * only the store's own sub-orders, in the store's country.
 */
class SubOrderPolicy
{
    public function __construct(private VendorWorkspace $workspace) {}

    public function manage(User $user, SubOrder $subOrder): bool
    {
        return $this->workspace->userOwns($user, (int) $subOrder->vendor_id);
    }
}
