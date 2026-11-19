<?php

namespace App\Domains\Catalog\Console;

use App\Domains\Catalog\Support\SearchText;
use App\Domains\Countries\Models\Country;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:search:rebuild-index')]
#[Description('Rebuild the products\' normalised search text (Arabic letter folding, brand / category names, SKUs) in every country (nightly)')]
class RebuildSearchIndexCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, SearchText $index): int
    {
        $forEachCountry->run('search-rebuild-index', function (Country $country) use ($index): void {
            $this->components->info("{$country->code}: ".$index->rebuild().' changed');
        });

        return self::SUCCESS;
    }
}
