<?php

namespace App\Domains\Documents\Console;

use App\Domains\Countries\Models\Country;
use App\Domains\Documents\Support\Documents;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:documents:expire')]
#[Description('Expire approved documents and certifications past valid_until (daily 01:30), in every country')]
class ExpireDocumentsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, Documents $documents): int
    {
        $forEachCountry->run('documents-expire', function (Country $country) use ($documents): void {
            $this->components->info("{$country->code}: expired {$documents->expire()}");
        });

        return self::SUCCESS;
    }
}
