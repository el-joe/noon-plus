<?php

namespace App\Domains\Reporting\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('nawy:reporting:create-database')]
#[Description('Create the MySQL reporting database (nawy_reporting) if it does not exist')]
class CreateReportingDatabaseCommand extends Command
{
    public function handle(): int
    {
        $connection = config('database.connections.reporting');

        if ($connection['driver'] !== 'mysql') {
            $this->components->warn("Skipping: the reporting driver is {$connection['driver']}");

            return self::SUCCESS;
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

        return self::SUCCESS;
    }
}
