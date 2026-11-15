<?php

namespace App\Domains\Campaigns\Console;

use App\Domains\Campaigns\Support\Invitations;
use App\Domains\Countries\Models\Country;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:campaigns:expire-invitations')]
#[Description('ProcessInvitationTimeoutJob: time out pending invitations past expires_at and search replacements, in every country')]
class ExpireInvitationsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, Invitations $invitations): int
    {
        $forEachCountry->run('invitation-timeout', function (Country $country) use ($invitations): void {
            $this->components->info("{$country->code}: timed out {$invitations->timeOutExpired()}");
        });

        return self::SUCCESS;
    }
}
