<?php

namespace App\Domains\Migration\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A row of `migration_quarantine` (plan §2.7) as Admin → Data migration
 * reviews it. `resolved_at` belongs to the migration (set when a later run
 * migrates the row); `review_status` is the admin's decision: `resolved`
 * (fixed at the source, waiting for the re-run) or `waived` (accepted as not
 * migrated). A waived row still counts as quarantined in reconciliation.
 *
 * @property int $id
 * @property string $step
 * @property string $source_table
 * @property string $source_id
 * @property string|null $country_code
 * @property string $reason
 * @property string|null $detail
 * @property array<string, mixed>|null $payload
 * @property string $last_run_id
 * @property Carbon|null $resolved_at
 * @property string|null $review_status
 * @property string|null $review_note
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 */
class QuarantineEntry extends Model
{
    public const STATUSES = ['open', 'resolved', 'auto_resolved', 'waived'];

    protected $table = 'migration_quarantine';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'resolved_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * open: waiting for a decision; resolved: an admin marked it fixed
     * (still quarantined until the re-run); auto_resolved: a later run
     * migrated it; waived: accepted as not migrated.
     */
    public function status(): string
    {
        return match (true) {
            $this->resolved_at !== null => 'auto_resolved',
            $this->review_status === 'waived' => 'waived',
            $this->review_status === 'resolved' => 'resolved',
            default => 'open',
        };
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeWithStatus(Builder $query, string $status): void
    {
        match ($status) {
            'open' => $query->whereNull('resolved_at')->whereNull('review_status'),
            'resolved' => $query->whereNull('resolved_at')->where('review_status', 'resolved'),
            'waived' => $query->whereNull('resolved_at')->where('review_status', 'waived'),
            'auto_resolved' => $query->whereNotNull('resolved_at'),
            default => null,
        };
    }
}
