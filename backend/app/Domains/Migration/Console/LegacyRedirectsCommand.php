<?php

namespace App\Domains\Migration\Console;

use App\Domains\Migration\Support\LegacySource;
use App\Domains\Migration\Support\RedirectMap;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

#[Signature('legacy:redirects {--dry-run : Show the counts without storing}')]
#[Description('WP11: build the 301 map from old storefront / portal / help URLs to the new website (legacy_redirects + storage/app/migration exports)')]
class LegacyRedirectsCommand extends Command
{
    public function handle(LegacySource $source): int
    {
        $map = new RedirectMap($source);

        if ($this->option('dry-run')) {
            $entries = $map->build();
        } else {
            $map->store();
            $entries = DB::table('legacy_redirects')->pluck('source', 'from_path')->all();
        }

        $bySource = array_count_values(array_map(fn ($entry): string => is_array($entry) ? $entry['source'] : (string) $entry, $entries));
        $this->components->info(count($entries).' redirect(s)'.($this->option('dry-run') ? ' (dry run, nothing stored)' : ' stored in legacy_redirects and exported to '.Storage::disk('local')->path((string) config('nawy.legacy.redirects_export')).' (+ .map for nginx)'));
        $this->table(['source', 'redirects'], array_map(fn (string $name, int $count): array => [$name, $count], array_keys($bySource), $bySource));

        return self::SUCCESS;
    }
}
