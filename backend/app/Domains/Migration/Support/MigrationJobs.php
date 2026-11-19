<?php

namespace App\Domains\Migration\Support;

use App\Domains\Migration\Jobs\RunMigrationJob;
use App\Domains\Migration\Models\MigrationJob;
use App\Domains\Migration\Models\QuarantineEntry;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Starts and runs the data-migration tasks of Admin → Data migration on the
 * existing machinery (Migrator / legacy:reconcile / legacy:redirects):
 * - a re-try is always a dry run first; apply() repeats exactly that dry run
 *   for real, so what was previewed is what runs;
 * - a single quarantined row runs in the request (one legacy row); a step
 *   for a country, reconcile and the redirect rebuild go to the `migration`
 *   queue (they read whole tables);
 * - quarantine entries resolve themselves when the re-run migrates the row
 *   (Quarantine::settle()).
 */
class MigrationJobs
{
    public const QUEUE = 'migration';

    public function previewRow(QuarantineEntry $entry, ?User $user): MigrationJob
    {
        $job = MigrationJob::query()->create([
            'kind' => 'row',
            'step' => $entry->step,
            'source_table' => $entry->source_table,
            'source_id' => $entry->source_id,
            'quarantine_id' => $entry->id,
            'dry_run' => true,
            'requested_by' => $user?->id,
        ]);

        $this->execute($job);

        return $job->refresh();
    }

    public function previewStep(string $step, ?string $countryCode, ?User $user): MigrationJob
    {
        $job = MigrationJob::query()->create([
            'kind' => 'step',
            'step' => $step,
            'country_code' => $countryCode,
            'dry_run' => true,
            'requested_by' => $user?->id,
        ]);

        RunMigrationJob::dispatch($job->id)->onQueue(self::QUEUE);

        return $job;
    }

    /**
     * Run a finished dry run for real (once).
     */
    public function apply(MigrationJob $preview, ?User $user): MigrationJob
    {
        $job = MigrationJob::query()->create([
            ...$preview->only(['kind', 'step', 'country_code', 'source_table', 'source_id', 'quarantine_id']),
            'dry_run' => false,
            'preview_id' => $preview->id,
            'requested_by' => $user?->id,
        ]);

        $job->kind === 'row' ? $this->execute($job) : RunMigrationJob::dispatch($job->id)->onQueue(self::QUEUE);

        return $job->refresh();
    }

    public function queue(string $kind, ?User $user): MigrationJob
    {
        $job = MigrationJob::query()->create(['kind' => $kind, 'requested_by' => $user?->id]);
        RunMigrationJob::dispatch($job->id)->onQueue(self::QUEUE);

        return $job;
    }

    public function execute(MigrationJob $job): void
    {
        $job->forceFill(['status' => 'running', 'started_at' => now()])->save();

        try {
            $result = match ($job->kind) {
                'row', 'step' => $this->migrate($job),
                'reconcile' => $this->artisan('legacy:reconcile', ['--report' => (string) config('nawy.legacy.reconcile_report')]),
                'redirects' => $this->artisan('legacy:redirects', []),
            };
            $job->forceFill([...$result, 'status' => 'done', 'finished_at' => now()])->save();
        } catch (Throwable $error) {
            report($error);
            $job->forceFill(['status' => 'failed', 'output' => mb_substr(Reconciler::mask($error->getMessage()), 0, 4000), 'finished_at' => now()])->save();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function migrate(MigrationJob $job): array
    {
        $only = $job->kind === 'row' ? ['table' => (string) $job->source_table, 'id' => (string) $job->source_id] : null;
        $context = MigrationContext::start($job->dry_run, $job->country_code ? [$job->country_code] : [], null, $only);
        $tables = (new Migrator($context, app(LegacySource::class)))->run((string) $job->step);
        $metrics = ['tables' => $tables];

        if ($job->kind === 'row') {
            $entry = $job->quarantine_id ? DB::table('migration_quarantine')->find($job->quarantine_id) : null;
            $metrics['row'] = [
                'read' => (int) ($tables[$job->source_table]['read'] ?? 0),
                'still_quarantined' => $entry !== null && $entry->resolved_at === null && ($job->dry_run ? (int) ($tables[$job->source_table]['quarantined'] ?? 0) > 0 : true),
            ];
        }

        return ['run_id' => $context->runId, 'metrics' => $metrics];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function artisan(string $command, array $arguments): array
    {
        $exit = Artisan::call($command, $arguments);

        return ['metrics' => ['exit_code' => $exit], 'output' => mb_substr(Reconciler::mask(Artisan::output()), 0, 20000)];
    }
}
