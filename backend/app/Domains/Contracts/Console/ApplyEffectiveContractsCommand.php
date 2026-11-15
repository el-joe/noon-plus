<?php

namespace App\Domains\Contracts\Console;

use App\Domains\Contracts\Support\ContractPublisher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:contracts:apply-effective')]
#[Description('Publish notice-mode contract versions whose effective date has come and record continued use as consent (hourly)')]
class ApplyEffectiveContractsCommand extends Command
{
    public function handle(ContractPublisher $publisher): int
    {
        $this->components->info('Versions applied: '.$publisher->applyEffective());

        return self::SUCCESS;
    }
}
