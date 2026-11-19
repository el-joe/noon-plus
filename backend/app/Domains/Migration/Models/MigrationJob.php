<?php

namespace App\Domains\Migration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A data-migration task started from Admin → Data migration
 * (`migration_jobs`): a single-row or step × country re-try (always a dry-run
 * preview first, then the run that applies it), a reconciliation or a
 * redirect-map rebuild. Step runs, reconcile and rebuilds go through the
 * `migration` queue; a single row runs in the request.
 *
 * @property int $id
 * @property string $kind row|step|reconcile|redirects
 * @property string|null $step
 * @property string|null $country_code
 * @property string|null $source_table
 * @property string|null $source_id
 * @property int|null $quarantine_id
 * @property bool $dry_run
 * @property int|null $preview_id
 * @property string $status queued|running|done|failed
 * @property string|null $run_id
 * @property array<string, mixed>|null $metrics
 * @property string|null $output
 * @property int|null $requested_by
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 */
class MigrationJob extends Model
{
    public const KINDS = ['row', 'step', 'reconcile', 'redirects'];

    protected $table = 'migration_jobs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'metrics' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['done', 'failed'], true);
    }

    /**
     * A finished dry run that has not been applied yet.
     */
    public function canApply(): bool
    {
        return $this->dry_run && $this->status === 'done' && in_array($this->kind, ['row', 'step'], true)
            && ! self::query()->where('preview_id', $this->id)->exists();
    }

    /**
     * Total of one metric over every table of the run.
     */
    public function total(string $metric): int
    {
        return (int) collect($this->metrics['tables'] ?? [])->sum(fn (array $counts): int => (int) ($counts[$metric] ?? 0));
    }
}
