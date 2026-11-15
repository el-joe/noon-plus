<?php

namespace App\Domains\LiveMedia\Console;

use App\Domains\Countries\Models\Country;
use App\Domains\LiveMedia\Support\LiveStreams;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:streams:sync-recorded')]
#[Description('Move scheduled audio/video channels to live and ended on their schedule, in every country (every minute)')]
class SyncRecordedStreamsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, LiveStreams $streams): int
    {
        $forEachCountry->run('sync-recorded-streams', function (Country $country) use ($streams): void {
            $this->components->info("{$country->code}: moved ".$streams->syncRecorded());
        });

        return self::SUCCESS;
    }
}
