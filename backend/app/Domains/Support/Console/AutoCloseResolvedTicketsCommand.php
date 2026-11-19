<?php

namespace App\Domains\Support\Console;

use App\Domains\Countries\Models\Country;
use App\Domains\Support\Support\SupportTickets;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:support:auto-close')]
#[Description('Close support tickets resolved more than support.auto_close_days ago, in every country (hourly)')]
class AutoCloseResolvedTicketsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, SupportTickets $tickets): int
    {
        $forEachCountry->run('support-auto-close', function (Country $country) use ($tickets): void {
            $this->components->info("{$country->code}: closed ".$tickets->autoClose());
        });

        return self::SUCCESS;
    }
}
