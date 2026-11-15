<?php

namespace App\Domains\Finance\Console;

use App\Domains\Countries\Models\Country;
use App\Domains\Finance\Support\Wallets;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:wallet:expire-promotional')]
#[Description('Expire promotional Nawy Credits past their expiry date, in every country (every 15 minutes)')]
class ExpirePromotionalCreditsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, Wallets $service): int
    {
        $forEachCountry->run('expire-promotional-credits', function (Country $country) use ($service): void {
            $this->components->info("{$country->code}: expired ".$service->expirePromotional());
        });

        return self::SUCCESS;
    }
}
