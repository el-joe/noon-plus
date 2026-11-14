<?php

namespace App\Domains\Countries\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:countries:migrate {--fresh : Drop all tables first} {--country=* : Only these country codes}')]
#[Description('Run database/migrations/country on every country database connection')]
class MigrateCountriesCommand extends Command
{
    public function handle(): int
    {
        $codes = $this->option('country') ?: array_keys(config('nawy.countries'));

        foreach ($codes as $code) {
            $connection = config('nawy.country_connection_prefix').$code;

            if (config("database.connections.{$connection}") === null) {
                $this->components->error("Unknown country connection {$connection}");

                return self::FAILURE;
            }

            $this->components->task("Migrating {$connection}", fn (): bool => $this->call(
                $this->option('fresh') ? 'migrate:fresh' : 'migrate',
                [
                    '--database' => $connection,
                    '--path' => 'database/migrations/country',
                    '--force' => true,
                ],
            ) === self::SUCCESS);
        }

        return self::SUCCESS;
    }
}
