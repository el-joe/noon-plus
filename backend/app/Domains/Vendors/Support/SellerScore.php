<?php

namespace App\Domains\Vendors\Support;

use App\Domains\Vendors\Models\Vendor;

/**
 * Seller score out of 100 (SRS §4.3 Performance): 100 minus the strike points
 * of the last `nawy.vendors.score_window_days` days (proposed rule, to be
 * replaced by the full SLA-based score in the performance work package).
 */
class SellerScore
{
    public function for(Vendor $vendor): int
    {
        $points = (int) $vendor->strikes()
            ->where('created_at', '>=', now()->subDays((int) config('nawy.vendors.score_window_days')))
            ->sum('points');

        return max(0, 100 - $points);
    }
}
