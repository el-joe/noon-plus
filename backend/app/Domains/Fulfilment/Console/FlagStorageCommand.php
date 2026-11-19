<?php

namespace App\Domains\Fulfilment\Console;

use App\Domains\Countries\Models\Country;
use App\Domains\Fulfilment\Support\StorageBilling;
use App\Shared\Scheduling\ForEachCountry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:fbn:flag-storage')]
#[Description('FBN flag (01:15): flag long-term and unsellable warehouse stock and tell the sellers, in every country')]
class FlagStorageCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, StorageBilling $billing): int
    {
        $forEachCountry->run('fbn-flag-storage', function (Country $country) use ($billing): void {
            $result = $billing->flag(CarbonImmutable::now());
            $this->components->info("{$country->code}: {$result['long_term']} long-term, {$result['unsellable']} unsellable");
        });

        return self::SUCCESS;
    }
}
