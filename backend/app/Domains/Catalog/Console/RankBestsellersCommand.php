<?php

namespace App\Domains\Catalog\Console;

use App\Domains\Catalog\Support\BestsellerRankings;
use App\Domains\Countries\Models\Country;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Weekly bestseller rankings (SRS FR-CAT-3; QC-015).
 */
#[Signature('nawy:catalog:rank-bestsellers')]
#[Description('Rank products by units sold in the last 30 days, in every country (weekly)')]
class RankBestsellersCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, BestsellerRankings $rankings): int
    {
        $forEachCountry->run('rank-bestsellers', function (Country $country) use ($rankings): void {
            $this->components->info("{$country->code}: ".$rankings->rank().' products ranked');
        });

        return self::SUCCESS;
    }
}
