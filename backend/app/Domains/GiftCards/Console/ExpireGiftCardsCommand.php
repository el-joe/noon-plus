<?php

namespace App\Domains\GiftCards\Console;

use App\Domains\Countries\Models\Country;
use App\Domains\GiftCards\Support\GiftCards;
use App\Domains\GiftCards\Support\Vouchers;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:gift-cards:expire')]
#[Description('Expire gift cards and vouchers past their end date, in every country (daily 00:30)')]
class ExpireGiftCardsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, GiftCards $giftCards, Vouchers $vouchers): int
    {
        $forEachCountry->run('expire-gift-cards', function (Country $country) use ($giftCards, $vouchers): void {
            $this->components->info("{$country->code}: gift cards {$giftCards->expire()}, vouchers {$vouchers->expire()}");
        });

        return self::SUCCESS;
    }
}
