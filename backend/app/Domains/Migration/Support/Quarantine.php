<?php

namespace App\Domains\Migration\Support;

use Illuminate\Support\Facades\DB;

/**
 * `migration_quarantine` (plan §2.7): a row whose country, owner or parent
 * cannot be resolved is parked with a reason — never dropped or guessed.
 * Entries a later run no longer quarantines are marked resolved. The admin
 * review (Admin → Data migration: resolved / waived with a note) is kept in
 * `review_status`; a manually resolved row quarantined again is reopened.
 */
class Quarantine
{
    /**
     * @var array<string, true>
     */
    private array $touched = [];

    /**
     * Rows quarantined in this run (also on dry runs).
     *
     * @var array<string, true>
     */
    private array $keys = [];

    public function __construct(private MigrationContext $context) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function put(string $table, string $sourceId, string $reason, ?string $detail = null, ?string $countryCode = null, array $payload = []): void
    {
        $this->context->count($table, 'quarantined');
        $this->keys[$table.'|'.$sourceId] = true;
        $this->touched[$table] = true;

        if ($this->context->dryRun) {
            return;
        }

        DB::table('migration_quarantine')->upsert([[
            'step' => $this->context->step,
            'source_table' => $table,
            'source_id' => $sourceId,
            'country_code' => $countryCode,
            'reason' => $reason,
            'detail' => $detail === null ? null : mb_substr($detail, 0, 500),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            'last_run_id' => $this->context->runId,
            'resolved_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['source_table', 'source_id'], ['step', 'country_code', 'reason', 'detail', 'payload', 'last_run_id', 'resolved_at', 'updated_at']);

        // A row an admin marked resolved that is still quarantined goes back to the queue (a waiver stands).
        DB::table('migration_quarantine')->where('source_table', $table)->where('source_id', $sourceId)->where('review_status', 'resolved')
            ->update(['review_status' => null, 'updated_at' => now()]);
    }

    public function has(string $table, ?string $sourceId): bool
    {
        if ($sourceId === null) {
            return false;
        }

        return isset($this->keys[$table.'|'.$sourceId])
            || DB::table('migration_quarantine')->where('source_table', $table)->where('source_id', $sourceId)->whereNull('resolved_at')->exists();
    }

    /**
     * A table was processed in full: its entries this run did not touch are
     * resolved. A partial run (one country, or one row from Admin → Data
     * migration) resolves only the untouched entries it has now migrated or
     * archived (an id-map entry, in one of its countries); `--since` runs
     * resolve nothing.
     */
    public function settle(string $table): void
    {
        if ($this->context->dryRun || $this->context->since !== null) {
            return;
        }

        $open = DB::table('migration_quarantine')
            ->where('source_table', $table)
            ->whereNull('resolved_at')
            ->where('last_run_id', '!=', $this->context->runId);

        if ($this->context->only === null && $this->context->countries === []) {
            $open->update(['resolved_at' => now(), 'updated_at' => now()]);

            return;
        }

        $open
            ->when($this->context->only !== null, fn ($query) => $query->where('source_id', $this->context->only['id']))
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('migration_id_map')
                ->whereColumn('migration_id_map.source_table', 'migration_quarantine.source_table')
                ->whereColumn('migration_id_map.source_id', 'migration_quarantine.source_id')
                ->when($this->context->countries !== [], fn ($map) => $map->whereIn('migration_id_map.target_db', $this->context->countries)))
            ->update(['resolved_at' => now(), 'updated_at' => now()]);
    }
}
