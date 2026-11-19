<?php

namespace App\Domains\Warranty\Support;

use App\Domains\Orders\Enums\ActorType;
use App\Domains\Warranty\Enums\WarrantyPurchaseStatus;
use App\Domains\Warranty\Models\WarrantyPurchase;
use App\Shared\StateMachine\StateMachine;

/**
 * ExpireWarrantyPurchasesJob (SRS §7.7, §17, daily 03:00): active extended
 * plans past `expires_at` become expired, one guarded move each with a
 * `system` history row (QC-053).
 */
class WarrantyExpiry
{
    public function __construct(private StateMachine $machine) {}

    public function run(): int
    {
        $expired = 0;

        WarrantyPurchase::query()
            ->where('status', WarrantyPurchaseStatus::Active)
            ->where('expires_at', '<=', now())
            ->chunkById(200, function ($purchases) use (&$expired): void {
                foreach ($purchases as $purchase) {
                    $this->machine->move($purchase, WarrantyPurchaseStatus::Expired, ActorType::System, null, 'Warranty period ended');
                    $expired++;
                }
            });

        return $expired;
    }
}
