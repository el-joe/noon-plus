<?php

namespace App\Domains\Payments\Console;

use App\Domains\Countries\Models\Country;
use App\Domains\Payments\Support\PendingPaymentExpiry;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:payments:expire-pending')]
#[Description('Cancel unpaid card/BNPL orders whose payment hold window has passed, in every country (runs every 5 minutes)')]
class ExpirePendingPaymentsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, PendingPaymentExpiry $expiry): int
    {
        $forEachCountry->run('expire-pending-payments', function (Country $country) use ($expiry): void {
            $this->components->info("{$country->code}: cancelled {$expiry->run()}");
        });

        return self::SUCCESS;
    }
}
