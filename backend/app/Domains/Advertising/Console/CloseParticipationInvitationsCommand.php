<?php

namespace App\Domains\Advertising\Console;

use App\Domains\Advertising\Support\CouponParticipations;
use App\Domains\Countries\Models\Country;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Aliases;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

// QC-039: the SRS (Appendix B) command name still works as an alias.
#[Aliases(['coupons:close-expired-participation-invitations'])]
#[Signature('nawy:coupons:close-participation-invitations')]
#[Description('coupons:close-expired-participation-invitations (every 15 min), in every country')]
class CloseParticipationInvitationsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, CouponParticipations $service): int
    {
        $forEachCountry->run('coupon-participation-close', function (Country $country) use ($service): void {
            $this->components->info("{$country->code}: closed {$service->closeExpired()}");
        });

        return self::SUCCESS;
    }
}
