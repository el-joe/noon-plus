<?php

namespace App\Domains\Membership\Console;

use App\Domains\Countries\Models\Country;
use App\Domains\Membership\Support\Memberships;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:membership:renew')]
#[Description('Renew, cancel or lapse Nawy One memberships at the end of their period, in every country (hourly)')]
class RenewMembershipsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, Memberships $service): int
    {
        $forEachCountry->run('renew-memberships', function (Country $country) use ($service): void {
            $this->components->info("{$country->code}: changed ".$service->renew());
        });

        return self::SUCCESS;
    }
}
