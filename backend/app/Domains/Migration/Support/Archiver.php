<?php

namespace App\Domains\Migration\Support;

/**
 * Copies a legacy row as it was into `legacy_records` of its country (or the
 * central database for global rows): rule R8 "archive", and every table the
 * new system has no field mapping for yet. Money stays exact because the
 * payload keeps the decimal strings untouched.
 */
class Archiver
{
    public function __construct(private MigrationContext $context, private IdMap $idMap) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public function archive(string $table, string $sourceId, array $row, string $targetDb, string $rule, string $reason): void
    {
        $payload = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
        $checksum = sha1($payload.$rule.$reason);
        $entry = $this->idMap->entry($table, $sourceId, $targetDb, 'legacy_records');

        if ($entry !== null && $entry['checksum'] === $checksum) {
            $this->context->count($table, 'archived_unchanged');

            return;
        }

        $id = $entry['target_id'] ?? -1;

        if (! $this->context->dryRun) {
            $connection = MigrationContext::db($targetDb);
            $connection->table('legacy_records')->upsert([[
                'source_table' => $table,
                'source_id' => $sourceId,
                'rule' => $rule,
                'reason' => mb_substr($reason, 0, 48),
                'payload' => $payload,
                'checksum' => $checksum,
                'archived_at' => now(),
            ]], ['source_table', 'source_id'], ['rule', 'reason', 'payload', 'checksum', 'archived_at']);
            $id = (int) $connection->table('legacy_records')->where('source_table', $table)->where('source_id', $sourceId)->value('id');
        }

        $this->idMap->remember($this->context, $table, $sourceId, $targetDb, 'legacy_records', $id, 'archived', $checksum);
        $this->context->count($table, $entry === null ? 'archived' : 'archived_updated');
    }
}
