<?php

namespace App\Domains\RealEstate\Console;

use App\Domains\Countries\Models\Country;
use App\Domains\RealEstate\Support\ExclusiveContracts;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:exclusive-contracts:sync')]
#[Description('Exclusive contracts (daily 00:45): activate scheduled grants and expire ended ones, in every country')]
class SyncExclusiveContractsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, ExclusiveContracts $service): int
    {
        $forEachCountry->run('exclusive-contracts', function (Country $country) use ($service): void {
            $r = $service->sync();
            $this->components->info("{$country->code}: activated {$r['activated']}, expired {$r['expired']}");
        });

        return self::SUCCESS;
    }
}
