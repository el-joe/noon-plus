<?php

namespace App\Domains\Cart\Console;

use App\Domains\Cart\Models\CartInventoryLock;
use App\Domains\Countries\Models\Country;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:cart:release-expired-locks')]
#[Description('Delete cart stock holds older than 15 minutes in every country (runs every minute)')]
class ReleaseExpiredCartLocksCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry): int
    {
        $forEachCountry->run('release-expired-cart-locks', function (Country $country): void {
            $released = CartInventoryLock::query()->where('expires_at', '<=', now())->delete();
            $this->components->info("{$country->code}: released {$released}");
        });

        return self::SUCCESS;
    }
}
