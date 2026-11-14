<?php

namespace App\Domains\Countries\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('nawy:countries:create-databases')]
#[Description('Create the MySQL database of every country connection if it does not exist')]
class CreateCountryDatabasesCommand extends Command
{
    public function handle(): int
    {
        foreach (array_keys(config('nawy.countries')) as $code) {
            $connection = config('database.connections.'.config('nawy.country_connection_prefix').$code);

            if ($connection['driver'] !== 'mysql') {
                $this->components->warn("Skipping {$code}: driver is {$connection['driver']}");

                continue;
            }

            $database = $connection['database'];

            if (! preg_match('/^[A-Za-z0-9_]+$/', $database)) {
                $this->components->error("Refusing unsafe database name {$database}");

                return self::FAILURE;
            }

            DB::connection(config('database.default'))->statement(
                "CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            );
            $this->components->info("Database {$database} is ready");
        }

        return self::SUCCESS;
    }
}
