<?php

namespace App\Domains\Migration\Console;

use App\Domains\Migration\Support\LegacySource;
use App\Domains\Migration\Support\MigrationContext;
use App\Domains\Migration\Support\Migrator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('legacy:migrate {step=all : A step name or "all"} {--country=* : Only write to these country codes} {--dry-run : Read and resolve everything, write nothing} {--since= : Only legacy rows changed since this date (incremental re-run)} {--row= : Only one legacy row, as table:id (re-try a quarantined row; needs a single step)}')]
#[Description('WP11: migrate the old platform snapshot (legacy connection, read-only) into the new central and country databases')]
class LegacyMigrateCommand extends Command
{
    public function handle(LegacySource $source): int
    {
        $countries = array_map('strtolower', (array) $this->option('country'));
        $unknown = array_diff($countries, MigrationContext::allCountries());

        if ($unknown !== []) {
            $this->components->error('Unknown country: '.implode(', ', $unknown));

            return self::FAILURE;
        }

        try {
            $steps = Migrator::resolveSteps((string) $this->argument('step'));
        } catch (InvalidArgumentException $invalid) {
            $this->components->error($invalid->getMessage());

            return self::FAILURE;
        }

        $only = null;

        if ($this->option('row')) {
            [$table, $id] = array_pad(explode(':', (string) $this->option('row'), 2), 2, '');

            if ($table === '' || $id === '' || count($steps) !== 1) {
                $this->components->error('--row needs table:id and a single step.');

                return self::FAILURE;
            }

            $only = ['table' => $table, 'id' => $id];
        }

        $context = MigrationContext::start(
            (bool) $this->option('dry-run'),
            $countries,
            $this->option('since') ? CarbonImmutable::parse((string) $this->option('since')) : null,
            $only,
        );
        $migrator = new Migrator($context, $source);

        $this->components->info(sprintf('Run %s%s — %s', $context->runId, $context->dryRun ? ' (dry run)' : '', $countries === [] ? 'all countries' : implode(', ', $countries)));

        foreach ($steps as $step) {
            $metrics = $migrator->run($step);
            $this->line("<options=bold>{$step}</>");
            $rows = [];

            foreach ($metrics as $table => $values) {
                $sums = $values['sums'] ?? [];
                unset($values['sums']);
                $rows[] = [$table, implode(' ', array_map(fn (string $key, int $count): string => "{$key}={$count}", array_keys($values), $values)), implode(' ', array_map(fn (string $currency, string $sum): string => "{$currency} {$sum}", array_keys($sums), $sums))];
            }

            $rows === [] ? $this->line('  (nothing to do)') : $this->table(['table', 'rows', 'sums'], $rows);
        }

        return self::SUCCESS;
    }
}
