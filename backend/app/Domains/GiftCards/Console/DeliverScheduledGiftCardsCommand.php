<?php

namespace App\Domains\GiftCards\Console;

use App\Domains\Countries\Models\Country;
use App\Domains\GiftCards\Support\GiftCards;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:gift-cards:deliver-scheduled')]
#[Description('Email gift cards whose send time has come, in every country (every 5 minutes)')]
class DeliverScheduledGiftCardsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, GiftCards $service): int
    {
        $forEachCountry->run('deliver-scheduled-gift-cards', function (Country $country) use ($service): void {
            $this->components->info("{$country->code}: delivered ".$service->deliverScheduled());
        });

        return self::SUCCESS;
    }
}
