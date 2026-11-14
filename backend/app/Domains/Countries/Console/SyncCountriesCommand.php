<?php

namespace App\Domains\Countries\Console;

use App\Domains\Countries\Models\Country;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:countries:sync')]
#[Description('Create or update the 8 country rows in the central database from config/nawy.php')]
class SyncCountriesCommand extends Command
{
    public function handle(): int
    {
        foreach (array_keys(config('nawy.countries')) as $code) {
            Country::query()->updateOrCreate(['code' => $code], Country::referenceAttributes($code));
            $this->components->info("Synced {$code}");
        }

        return self::SUCCESS;
    }
}
