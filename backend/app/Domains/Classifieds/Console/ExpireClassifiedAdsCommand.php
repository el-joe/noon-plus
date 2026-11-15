<?php

namespace App\Domains\Classifieds\Console;

use App\Domains\Classifieds\Support\ClassifiedExpiry;
use App\Domains\Countries\Models\Country;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:classifieds:expire')]
#[Description('Classified expiry job: renewal reminders, then expire live / paused ads past their period, in every country')]
class ExpireClassifiedAdsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, ClassifiedExpiry $service): int
    {
        $forEachCountry->run('classified-expiry', function (Country $country) use ($service): void {
            $r = $service->run();
            $this->components->info("{$country->code}: reminded {$r['reminded']}, expired {$r['expired']}");
        });

        return self::SUCCESS;
    }
}
