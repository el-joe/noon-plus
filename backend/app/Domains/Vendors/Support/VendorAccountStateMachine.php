<?php

namespace App\Domains\Vendors\Support;

use App\Domains\Orders\Enums\ActorType;
use App\Domains\Vendors\Enums\VendorStatus;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\StateMachine\StringStatusMachine;
use App\Shared\StateMachine\TransitionRefused;

/**
 * Vendor account lifecycle (catalogue Appendix A; QC-016): every move needs
 * a reason and writes a history row with the admin.
 */
class VendorAccountStateMachine
{
    public function __construct(private StringStatusMachine $machine) {}

    public function move(Vendor $vendor, VendorStatus $to, ActorType $actorType, ?int $actorUserId, string $reason): Vendor
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw new TransitionRefused(__('Give a reason.'));
        }

        return $this->machine->move($vendor, VendorStatus::class, $to, $actorType, $actorUserId, trim($reason));
    }
}
