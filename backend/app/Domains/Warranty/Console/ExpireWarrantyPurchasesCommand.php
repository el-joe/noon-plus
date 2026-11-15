<?php

namespace App\Domains\Warranty\Console;

use App\Domains\Countries\Models\Country;
use App\Domains\Warranty\Support\WarrantyExpiry;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:warranty:expire')]
#[Description('Expire extended warranty plans past their end date, in every country (daily 03:00)')]
class ExpireWarrantyPurchasesCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, WarrantyExpiry $service): int
    {
        $forEachCountry->run('expire-warranty-purchases', function (Country $country) use ($service): void {
            $this->components->info("{$country->code}: expired ".$service->run());
        });

        return self::SUCCESS;
    }
}
