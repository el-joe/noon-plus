<?php

namespace App\Domains\Reporting\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:reporting:migrate {--fresh : Drop all tables first (local and testing only)}')]
#[Description('Run database/migrations/reporting on the reporting database connection')]
class MigrateReportingCommand extends Command
{
    public function handle(): int
    {
        if ($this->option('fresh') && ! app()->environment(['local', 'testing'])) {
            $this->components->error('--fresh is only allowed in local and testing.');

            return self::FAILURE;
        }

        return $this->call($this->option('fresh') ? 'migrate:fresh' : 'migrate', [
            '--database' => 'reporting',
            '--path' => 'database/migrations/reporting',
            '--force' => true,
        ]);
    }
}
