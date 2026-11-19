<?php

namespace App\Domains\Delivery\Console;

use App\Domains\Countries\Models\Country;
use App\Domains\Delivery\Support\AgentPay;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:delivery:issue-payslips')]
#[Description('Issue delivery agent payslips for every pay period that has ended and credit the agent wallets, in every country (daily 02:30)')]
class IssuePayslipsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, AgentPay $pay): int
    {
        $forEachCountry->run('issue-delivery-payslips', function (Country $country) use ($pay): void {
            $this->components->info("{$country->code}: issued ".$pay->issueDue($country));
        });

        return self::SUCCESS;
    }
}
