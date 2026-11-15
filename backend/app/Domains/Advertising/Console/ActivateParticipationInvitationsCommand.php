<?php

namespace App\Domains\Advertising\Console;

use App\Domains\Advertising\Support\CouponParticipations;
use App\Domains\Countries\Models\Country;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:coupons:activate-participation-invitations')]
#[Description('Activate fulfilled coupon participation invitations (daily 00:30), in every country')]
class ActivateParticipationInvitationsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, CouponParticipations $service): int
    {
        $forEachCountry->run('coupon-participation-activate', function (Country $country) use ($service): void {
            $r = $service->activateFulfilled();
            $this->components->info("{$country->code}: activated {$r['activated']}, ended {$r['ended']}");
        });

        return self::SUCCESS;
    }
}
