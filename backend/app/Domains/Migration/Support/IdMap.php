<?php

namespace App\Domains\Migration\Support;

use Illuminate\Support\Facades\DB;

/**
 * `migration_id_map` (plan §2.2): legacy id → target row per target database.
 * Loaded per source table on first use; dry runs keep their (negative)
 * placeholder ids in memory only.
 */
class IdMap
{
    /**
     * @var array<string, array<string, array{target_id: int, origin: string, checksum: string}>>
     */
    private array $loaded = [];

    public function find(string $sourceTable, ?string $sourceId, string $targetDb, string $targetTable): ?int
    {
        return $this->entry($sourceTable, $sourceId, $targetDb, $targetTable)['target_id'] ?? null;
    }

    /**
     * @return array{target_id: int, origin: string, checksum: string}|null
     */
    public function entry(string $sourceTable, ?string $sourceId, string $targetDb, string $targetTable): ?array
    {
        if ($sourceId === null || $sourceId === '') {
            return null;
        }

        $this->load($sourceTable);

        return $this->loaded[$sourceTable][$this->key($sourceId, $targetDb, $targetTable)] ?? null;
    }

    /**
     * Target ids of a legacy row in every database it went to.
     *
     * @return array<string, int> target db => id
     */
    public function targets(string $sourceTable, ?string $sourceId, string $targetTable): array
    {
        if ($sourceId === null) {
            return [];
        }

        $this->load($sourceTable);
        $found = [];

        foreach ($this->loaded[$sourceTable] as $key => $entry) {
            [$id, $db, $table] = explode('|', $key);

            if ($id === $sourceId && $table === $targetTable) {
                $found[$db] = $entry['target_id'];
            }
        }

        return $found;
    }

    public function remember(MigrationContext $context, string $sourceTable, string $sourceId, string $targetDb, string $targetTable, int $targetId, string $origin, string $checksum): void
    {
        $this->load($sourceTable);
        $this->loaded[$sourceTable][$this->key($sourceId, $targetDb, $targetTable)] = ['target_id' => $targetId, 'origin' => $origin, 'checksum' => $checksum];

        if ($context->dryRun) {
            return;
        }

        DB::table('migration_id_map')->upsert([[
            'source_table' => $sourceTable,
            'source_id' => $sourceId,
            'target_db' => $targetDb,
            'target_table' => $targetTable,
            'target_id' => $targetId,
            'origin' => $origin,
            'checksum' => $checksum,
            'migrated_at' => now(),
        ]], ['source_table', 'source_id', 'target_db', 'target_table'], ['target_id', 'origin', 'checksum', 'migrated_at']);
    }

    public function forget(): void
    {
        $this->loaded = [];
    }

    private function load(string $sourceTable): void
    {
        if (isset($this->loaded[$sourceTable])) {
            return;
        }

        $this->loaded[$sourceTable] = [];

        foreach (DB::table('migration_id_map')->where('source_table', $sourceTable)->get() as $row) {
            $this->loaded[$sourceTable][$this->key($row->source_id, $row->target_db, $row->target_table)] = [
                'target_id' => (int) $row->target_id,
                'origin' => $row->origin,
                'checksum' => $row->checksum,
            ];
        }
    }

    private function key(string $sourceId, string $targetDb, string $targetTable): string
    {
        return $sourceId.'|'.$targetDb.'|'.$targetTable;
    }
}
