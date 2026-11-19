<?php

namespace App\Domains\Coupons\Console;

use App\Domains\Advertising\Enums\ParticipationInvitationStatus;
use App\Domains\Advertising\Models\CouponParticipationInvitation;
use App\Domains\Countries\Models\Country;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Orders\Enums\ActorType;
use App\Shared\Scheduling\ForEachCountry;
use App\Shared\StateMachine\StateMachine;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Coupon expiry (SRS §17, MASTER_PLAN §11 daily 00:15; QC-015): coupons
 * past their end are switched off, and active coupon-participation
 * invitations whose coupon has ended move to `ended` (lifecycle §4.5
 * active → ended). Idempotent.
 */
#[Signature('nawy:coupons:expire')]
#[Description('Switch off ended coupons and end their participation invitations, in every country (daily 00:15)')]
class ExpireCouponsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, StateMachine $machine): int
    {
        $forEachCountry->run('expire-coupons', function (Country $country) use ($machine): void {
            $switchedOff = Coupon::query()->where('is_active', true)->whereNotNull('ends_at')->where('ends_at', '<=', now())->update(['is_active' => false]);
            $ended = 0;

            CouponParticipationInvitation::query()
                ->where('status', ParticipationInvitationStatus::Active)
                ->whereHas('coupon', fn ($coupon) => $coupon->whereNotNull('ends_at')->where('ends_at', '<=', now()))
                ->each(function (CouponParticipationInvitation $invitation) use ($machine, &$ended): void {
                    $machine->move($invitation, ParticipationInvitationStatus::Ended, ActorType::System, null, 'Coupon ended');
                    $ended++;
                });

            $this->components->info("{$country->code}: {$switchedOff} coupons switched off, {$ended} invitations ended");
        });

        return self::SUCCESS;
    }
}
