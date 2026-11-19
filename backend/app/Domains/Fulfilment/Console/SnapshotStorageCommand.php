<?php

namespace App\Domains\Fulfilment\Console;

use App\Domains\Countries\Models\Country;
use App\Domains\Fulfilment\Support\StorageBilling;
use App\Shared\Scheduling\ForEachCountry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:fbn:storage-snapshot')]
#[Description('FBN overage (01:00): daily storage snapshot per warehouse and listing, warehouse capacity overage, in every country')]
class SnapshotStorageCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, StorageBilling $billing): int
    {
        $forEachCountry->run('fbn-storage-snapshot', function (Country $country) use ($billing): void {
            $result = $billing->snapshot(CarbonImmutable::now($country->timezone));
            $this->components->info("{$country->code}: {$result['rows']} rows, {$result['over_capacity']} warehouses over capacity");
        });

        return self::SUCCESS;
    }
}
