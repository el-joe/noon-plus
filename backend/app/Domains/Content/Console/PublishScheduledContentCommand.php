<?php

namespace App\Domains\Content\Console;

use App\Domains\Content\Support\BlogPosts;
use App\Domains\Content\Support\Pages;
use App\Domains\Countries\Models\Country;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:content:publish-scheduled')]
#[Description('Publish scheduled pages and blog posts whose time has come, in every country (every 5 minutes)')]
class PublishScheduledContentCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, Pages $pages, BlogPosts $posts): int
    {
        $forEachCountry->run('publish-scheduled-content', function (Country $country) use ($pages, $posts): void {
            $this->components->info("{$country->code}: pages ".$pages->publishDue().', posts '.$posts->publishDue());
        });

        return self::SUCCESS;
    }
}
