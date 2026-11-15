<?php

namespace App\Domains\Classifieds\Console;

use App\Domains\Classifieds\Support\SavedSearches;
use App\Domains\Countries\Models\Country;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:classifieds:saved-search-alerts')]
#[Description('Saved-search alerts (hourly): new live ads matching saved searches, in every country')]
class SendSavedSearchAlertsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, SavedSearches $service): int
    {
        $forEachCountry->run('saved-search-alerts', function (Country $country) use ($service): void {
            $this->components->info("{$country->code}: alerts {$service->alertDue()}");
        });

        return self::SUCCESS;
    }
}
