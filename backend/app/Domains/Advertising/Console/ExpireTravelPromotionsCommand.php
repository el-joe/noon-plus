<?php

namespace App\Domains\Advertising\Console;

use App\Domains\Advertising\Support\TravelPromotions;
use App\Domains\Countries\Models\Country;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:travel:expire-promotions')]
#[Description('Travel promotions (D14, every 5 min): ended paid windows expire and unpaid promotions are cancelled, in every country')]
class ExpireTravelPromotionsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, TravelPromotions $promotions): int
    {
        $forEachCountry->run('travel-promotion-expiry', function (Country $country) use ($promotions): void {
            $result = $promotions->expire();
            $this->components->info("{$country->code}: expired {$result['expired']}, cancelled unpaid {$result['cancelled']}");
        });

        return self::SUCCESS;
    }
}
