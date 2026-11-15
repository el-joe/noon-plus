<?php

namespace App\Domains\Advertising\Console;

use App\Domains\Advertising\Support\AdPackageSubscriptions;
use App\Domains\Countries\Models\Country;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:ad-packages:expire')]
#[Description('ad-packages:expire (hourly): end ad-package subscriptions past their end, in every country')]
class ExpireAdPackagesCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, AdPackageSubscriptions $service): int
    {
        $forEachCountry->run('ad-packages-expire', function (Country $country) use ($service): void {
            $this->components->info("{$country->code}: expired {$service->expireDue()}");
        });

        return self::SUCCESS;
    }
}
