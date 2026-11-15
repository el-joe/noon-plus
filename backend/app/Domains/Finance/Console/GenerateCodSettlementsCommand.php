<?php

namespace App\Domains\Finance\Console;

use App\Domains\Countries\Models\Country;
use App\Domains\Finance\Support\CodSettlements;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:cod:generate-settlements')]
#[Description('Group the unsettled COD collections per agent into settlements, in every country (daily 23:30)')]
class GenerateCodSettlementsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, CodSettlements $service): int
    {
        $forEachCountry->run('generate-cod-settlements', function (Country $country) use ($service): void {
            $this->components->info("{$country->code}: created ".$service->generate($country));
        });

        return self::SUCCESS;
    }
}
