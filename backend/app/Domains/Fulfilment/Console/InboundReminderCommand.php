<?php

namespace App\Domains\Fulfilment\Console;

use App\Domains\Countries\Models\Country;
use App\Domains\Fulfilment\Support\InboundReminders;
use App\Shared\Scheduling\ForEachCountry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:fbn:inbound-reminders')]
#[Description('FBN inbound reminder (weekly): remind sellers of submitted plans not shipped yet, in every country')]
class InboundReminderCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, InboundReminders $reminders): int
    {
        $forEachCountry->run('fbn-inbound-reminders', function (Country $country) use ($reminders): void {
            $this->components->info("{$country->code}: {$reminders->send(CarbonImmutable::now())} reminders");
        });

        return self::SUCCESS;
    }
}
