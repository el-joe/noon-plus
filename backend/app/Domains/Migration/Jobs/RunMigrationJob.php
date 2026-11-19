<?php

namespace App\Domains\Migration\Jobs;

use App\Domains\Migration\Models\MigrationJob;
use App\Domains\Migration\Support\MigrationJobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs one Admin → Data migration task on the `migration` queue (step ×
 * country re-try or its dry-run preview, reconcile, redirect rebuild).
 * Never retried automatically: a migration run is started again by an admin.
 */
class RunMigrationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3500;

    public function __construct(public int $migrationJobId) {}

    public function handle(MigrationJobs $jobs): void
    {
        $job = MigrationJob::query()->find($this->migrationJobId);

        if ($job === null || $job->status !== 'queued') {
            return;
        }

        $jobs->execute($job);
    }

    public function failed(): void
    {
        MigrationJob::query()->whereKey($this->migrationJobId)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'output' => 'The queue worker stopped the job (timeout or crash).', 'finished_at' => now()]);
    }
}
