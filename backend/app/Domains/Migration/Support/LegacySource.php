<?php

namespace App\Domains\Migration\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Read-only access to the old platform's snapshot (`legacy` connection,
 * sessions opened READ ONLY — plan §2.1). Rows come back as arrays; lookups
 * by id are memoised for the run.
 */
class LegacySource
{
    public const CONNECTION = 'legacy';

    /**
     * @var array<string, bool>|null
     */
    private ?array $tables = null;

    /**
     * @var array<string, array<int, string>>
     */
    private array $columns = [];

    /**
     * @var array<string, array<string, array<string, mixed>|null>>
     */
    private array $found = [];

    public function connection(): Connection
    {
        return DB::connection(self::CONNECTION);
    }

    public function hasTable(string $table): bool
    {
        if ($this->tables === null) {
            $this->tables = [];

            foreach ($this->connection()->select('SHOW TABLES') as $row) {
                $this->tables[(string) array_values((array) $row)[0]] = true;
            }
        }

        return isset($this->tables[$table]);
    }

    /**
     * @return array<int, string>
     */
    public function columns(string $table): array
    {
        return $this->columns[$table] ??= array_map(
            fn (object $column): string => $column->Field,
            $this->connection()->select('SHOW COLUMNS FROM `'.str_replace('`', '', $table).'`'),
        );
    }

    public function hasColumn(string $table, string $column): bool
    {
        return $this->hasTable($table) && in_array($column, $this->columns($table), true);
    }

    public function count(string $table): int
    {
        return $this->hasTable($table) ? $this->connection()->table($table)->count() : 0;
    }

    /**
     * Every row of a table, ordered by id when it has one. `$since` keeps only
     * rows changed since then (incremental re-runs); `$onlyKey` keeps the one
     * row with that key (a single-row re-run, see rowKey()).
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function rows(string $table, ?CarbonInterface $since = null, ?string $onlyKey = null): LazyCollection
    {
        if (! $this->hasTable($table)) {
            return LazyCollection::empty();
        }

        $query = $this->connection()->table($table);

        if ($since !== null) {
            $column = $this->hasColumn($table, 'updated_at') ? 'updated_at' : ($this->hasColumn($table, 'created_at') ? 'created_at' : null);

            if ($column !== null) {
                $query->where($column, '>=', $since);
            }
        }

        if ($this->hasColumn($table, 'id')) {
            $query->orderBy('id');

            if ($onlyKey !== null) {
                $query->where('id', $onlyKey);
            }
        }

        $rows = $query->cursor()->map(fn (object $row): array => (array) $row);

        return $onlyKey === null ? $rows : $rows->filter(fn (array $row): bool => $this->rowKey($table, $row) === $onlyKey)->values();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $table, ?string $id, string $column = 'id'): ?array
    {
        if ($id === null || $id === '' || ! $this->hasTable($table)) {
            return null;
        }

        $key = $table.'.'.$column;

        if (! array_key_exists($id, $this->found[$key] ?? [])) {
            $row = $this->connection()->table($table)->where($column, $id)->first();
            $this->found[$key][$id] = $row === null ? null : (array) $row;
        }

        return $this->found[$key][$id];
    }

    /**
     * The stable key of a row: its id, or a hash of the row for tables
     * without one (pivots such as role_has_permissions).
     *
     * @param  array<string, mixed>  $row
     */
    public function rowKey(string $table, array $row): string
    {
        if (array_key_exists('id', $row) && $row['id'] !== null) {
            return (string) $row['id'];
        }

        if (array_key_exists('key', $row) && $table === 'cache') {
            return sha1((string) $row['key']);
        }

        return sha1(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) ?: '');
    }
}
