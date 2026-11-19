<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;

/**
 * A small legacy snapshot for the WP11 migration tests: the `nawy_test_legacy`
 * database (the `legacy` connection under phpunit) built from
 * tests/Fixtures/legacy/schema.sql and filled per test. It is written through
 * its own PDO handle — the application's `legacy` connection stays read-only —
 * and never points at the real snapshot.
 */
class LegacyFixture
{
    /**
     * Legacy country ids per new country code.
     *
     * @var array<string, string>
     */
    public const COUNTRIES = [
        'uae' => '00000000-0000-4000-8000-000000000001', 'omn' => '00000000-0000-4000-8000-000000000002',
        'ksa' => '00000000-0000-4000-8000-000000000003', 'egy' => '00000000-0000-4000-8000-000000000004',
        'kwt' => '00000000-0000-4000-8000-000000000005', 'qat' => '00000000-0000-4000-8000-000000000006',
        'bhr' => '00000000-0000-4000-8000-000000000007', 'jor' => '00000000-0000-4000-8000-000000000008',
    ];

    private static bool $created = false;

    private static ?PDO $pdo = null;

    /**
     * Fresh, empty legacy tables plus the 8 legacy countries.
     */
    public static function reset(): void
    {
        $database = (string) config('database.connections.legacy.database');

        if (! str_contains($database, 'test')) {
            throw new \RuntimeException("Refusing to build a fixture in {$database}: the legacy connection must point at a test database.");
        }

        if (! self::$created) {
            $server = new PDO(self::dsn(null), (string) config('database.connections.legacy.username'), (string) config('database.connections.legacy.password'));
            $server->exec("DROP DATABASE IF EXISTS `{$database}`");
            $server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            self::pdo()->exec((string) file_get_contents(base_path('tests/Fixtures/legacy/schema.sql')));
            self::$created = true;
        } else {
            foreach (self::pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
                self::pdo()->exec("TRUNCATE TABLE `{$table}`");
            }
        }

        foreach (config('nawy.countries') as $code => $country) {
            self::insert('countries', [[
                'id' => self::COUNTRIES[$code], 'iso_code_2' => $country['iso2'], 'iso_code_3' => strtoupper($code), 'site_code' => $code,
                'name_en' => $country['name_en'], 'name_ar' => $country['name_ar'], 'currency_code' => $country['currency'],
                'phone_prefix' => $country['phone_code'], 'default_locale' => $country['default_locale'], 'timezone' => $country['timezone'],
                'is_active' => 1, 'vat_rate' => $country['tax_rate'],
            ]]);
        }

        DB::purge('legacy');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function insert(string $table, array $rows): void
    {
        foreach ($rows as $row) {
            $columns = array_keys($row);
            $statement = self::pdo()->prepare(sprintf(
                'INSERT INTO `%s` (%s) VALUES (%s)',
                $table,
                implode(', ', array_map(fn (string $column): string => "`{$column}`", $columns)),
                implode(', ', array_fill(0, count($columns), '?')),
            ));
            $statement->execute(array_map(fn ($value) => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (is_bool($value) ? (int) $value : $value), array_values($row)));
        }
    }

    public static function update(string $table, string $id, array $values): void
    {
        $sets = implode(', ', array_map(fn (string $column): string => "`{$column}` = ?", array_keys($values)));
        self::pdo()->prepare("UPDATE `{$table}` SET {$sets} WHERE id = ?")->execute([...array_values($values), $id]);
    }

    public static function uuid(): string
    {
        return (string) Str::uuid();
    }

    private static function pdo(): PDO
    {
        return self::$pdo ??= new PDO(self::dsn((string) config('database.connections.legacy.database')), (string) config('database.connections.legacy.username'), (string) config('database.connections.legacy.password'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true]);
    }

    private static function dsn(?string $database): string
    {
        $config = config('database.connections.legacy');

        return sprintf('mysql:host=%s;port=%s%s;charset=utf8mb4', $config['host'], $config['port'], $database === null ? '' : ";dbname={$database}");
    }
}
