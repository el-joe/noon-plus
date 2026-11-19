<?php

namespace App\Domains\Migration\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Idempotent upserts into the new databases (plan §2.2). A legacy row that
 * is already mapped with the same checksum is left alone (a re-run is a
 * no-op); a changed one is updated in place — unless it was *linked* to a
 * row that already existed (reference data matched by natural key, or a
 * person merged into an existing account), which is never overwritten.
 * Dry runs write nothing and hand out negative placeholder ids.
 */
class TargetWriter
{
    private int $placeholder = 0;

    /**
     * @var array<string, bool>
     */
    private array $legacyColumns = [];

    /**
     * NOT NULL columns with a default, per target table: a null value takes the default.
     *
     * @var array<string, array<string, true>>
     */
    private array $defaulted = [];

    public function __construct(private MigrationContext $context, private IdMap $idMap) {}

    /**
     * @param  array<string, mixed>  $values  target columns (arrays are stored as JSON)
     * @param  array<string, mixed>|null  $match  natural key: link to an existing row instead of inserting
     */
    public function upsert(string $sourceTable, string $sourceId, string $targetDb, string $targetTable, array $values, ?array $match = null, bool $countMetrics = true): int
    {
        $values = $this->withDefaults($targetDb, $targetTable, $this->encode($values));
        $checksum = sha1(serialize(array_diff_key($values, ['created_at' => true, 'updated_at' => true])));
        $entry = $this->idMap->entry($sourceTable, $sourceId, $targetDb, $targetTable);
        $metric = fn (string $name) => $countMetrics ? $this->context->count($sourceTable, $name) : null;

        if ($entry !== null) {
            if ($entry['checksum'] === $checksum || $entry['origin'] !== 'inserted') {
                $metric('unchanged');

                return $entry['target_id'];
            }

            if (! $this->context->dryRun) {
                MigrationContext::db($targetDb)->table($targetTable)->where('id', $entry['target_id'])->update($values);
            }

            $this->idMap->remember($this->context, $sourceTable, $sourceId, $targetDb, $targetTable, $entry['target_id'], 'inserted', $checksum);
            $metric('updated');

            return $entry['target_id'];
        }

        if ($match !== null) {
            $existing = MigrationContext::db($targetDb)->table($targetTable)->where($match)->value('id');

            if ($existing !== null) {
                $this->idMap->remember($this->context, $sourceTable, $sourceId, $targetDb, $targetTable, (int) $existing, 'linked', $checksum);
                $metric('linked');

                return (int) $existing;
            }
        }

        if ($this->context->dryRun) {
            $id = --$this->placeholder;
        } else {
            if ($this->hasLegacyId($targetDb, $targetTable) && ! array_key_exists('legacy_id', $values)) {
                $values['legacy_id'] = strlen($sourceId) <= 36 ? $sourceId : null;
            }

            $id = (int) MigrationContext::db($targetDb)->table($targetTable)->insertGetId($values);
        }

        $this->idMap->remember($this->context, $sourceTable, $sourceId, $targetDb, $targetTable, $id, 'inserted', $checksum);
        $metric('written');

        return $id;
    }

    /**
     * Record that a legacy row lives on as an existing target row (identity merge).
     */
    public function link(string $sourceTable, string $sourceId, string $targetDb, string $targetTable, int $targetId, string $origin = 'merged'): void
    {
        if ($this->idMap->find($sourceTable, $sourceId, $targetDb, $targetTable) === $targetId) {
            $this->context->count($sourceTable, 'unchanged');

            return;
        }

        $this->idMap->remember($this->context, $sourceTable, $sourceId, $targetDb, $targetTable, $targetId, $origin, sha1($origin.$targetId));
        $this->context->count($sourceTable, $origin);
    }

    /**
     * A value not yet used in a unique column: the legacy value itself, or
     * "{value}-legacy[-n]" when an existing (non-migrated) row holds it.
     */
    public function unique(string $targetDb, string $targetTable, string $column, string $value, ?int $ownId = null, array $scope = [], int $maxLength = 255): string
    {
        $candidate = mb_substr($value, 0, $maxLength);
        $suffix = 0;

        while (true) {
            $taken = MigrationContext::db($targetDb)->table($targetTable)->where($scope)->where($column, $candidate)
                ->when($ownId !== null && $ownId > 0, fn ($query) => $query->where('id', '!=', $ownId))
                ->exists();

            if (! $taken) {
                return $candidate;
            }

            $tail = '-legacy'.($suffix > 0 ? '-'.$suffix : '');
            $candidate = mb_substr($value, 0, $maxLength - strlen($tail)).$tail;
            $suffix++;
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function encode(array $values): array
    {
        foreach ($values as $column => $value) {
            if (is_array($value)) {
                $values[$column] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            } elseif (is_bool($value)) {
                $values[$column] = $value ? 1 : 0;
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function withDefaults(string $targetDb, string $targetTable, array $values): array
    {
        $key = $targetDb.'.'.$targetTable;

        if (! isset($this->defaulted[$key])) {
            $this->defaulted[$key] = [];

            foreach (Schema::connection(MigrationContext::connectionName($targetDb))->getColumns($targetTable) as $column) {
                if (! $column['nullable'] && $column['default'] !== null) {
                    $this->defaulted[$key][$column['name']] = true;
                }
            }
        }

        foreach ($values as $column => $value) {
            if ($value === null && isset($this->defaulted[$key][$column])) {
                unset($values[$column]);
            }
        }

        return $values;
    }

    private function hasLegacyId(string $targetDb, string $targetTable): bool
    {
        return $this->legacyColumns[$targetDb.'.'.$targetTable] ??= Schema::connection(MigrationContext::connectionName($targetDb))->hasColumn($targetTable, 'legacy_id');
    }
}
