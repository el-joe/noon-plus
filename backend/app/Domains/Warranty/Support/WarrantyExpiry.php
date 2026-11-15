<?php

namespace App\Domains\Warranty\Support;

use App\Domains\Warranty\Enums\WarrantyPurchaseStatus;
use App\Domains\Warranty\Models\WarrantyPurchase;

/**
 * ExpireWarrantyPurchasesJob (SRS §7.7, §17, daily 03:00): active extended
 * plans past `expires_at` become expired.
 */
class WarrantyExpiry
{
    public function run(): int
    {
        return WarrantyPurchase::query()
            ->where('status', WarrantyPurchaseStatus::Active)
            ->where('expires_at', '<=', now())
            ->update(['status' => WarrantyPurchaseStatus::Expired, 'updated_at' => now()]);
    }
}
