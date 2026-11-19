<?php

namespace App\Domains\Migration\Support;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One `legacy:migrate` run: run id, dry-run flag, country filter, the step
 * being run and its metrics (rows read, written, updated, unchanged, linked,
 * folded, archived, dropped, skipped, quarantined per table, money sums per
 * currency — plan §3).
 */
class MigrationContext
{
    public const CENTRAL = 'central';

    public string $step = '';

    /**
     * @var array<string, array<string, array<string, int>>>
     */
    private array $metrics = [];

    /**
     * @var array<string, array<string, array<string, BigDecimal>>>
     */
    private array $sums = [];

    /**
     * @param  array<int, string>  $countries  country codes this run may write to (empty = all)
     * @param  array{table: string, id: string}|null  $only  a single-row re-run (Admin → Data migration): only this legacy row of its table
     */
    public function __construct(
        public readonly string $runId,
        public readonly bool $dryRun = false,
        public readonly array $countries = [],
        public readonly ?CarbonImmutable $since = null,
        public readonly ?array $only = null,
    ) {}

    /**
     * @param  array<int, string>  $countries
     * @param  array{table: string, id: string}|null  $only
     */
    public static function start(bool $dryRun = false, array $countries = [], ?CarbonImmutable $since = null, ?array $only = null): self
    {
        return new self((string) Str::uuid(), $dryRun, array_values($countries), $since, $only);
    }

    /**
     * A single-row re-run that does not cover this table.
     */
    public function skipsTable(string $table): bool
    {
        return $this->only !== null && $this->only['table'] !== $table;
    }

    /**
     * Rows of a table read so far in the current step.
     */
    public function readCount(string $table): int
    {
        return $this->metrics[$this->step][$table]['read'] ?? 0;
    }

    /**
     * `migration_runs.scope` of this run: null for a full step run.
     */
    public function scope(): ?string
    {
        return $this->only === null ? null : mb_substr('row:'.$this->only['table'].':'.$this->only['id'], 0, 160);
    }

    /**
     * Every launched country code (config/nawy.php).
     *
     * @return array<int, string>
     */
    public static function allCountries(): array
    {
        return array_keys(config('nawy.countries'));
    }

    public function allows(string $countryCode): bool
    {
        return $this->countries === [] || in_array($countryCode, $this->countries, true);
    }

    /**
     * Country codes this run writes to.
     *
     * @return array<int, string>
     */
    public function targetCountries(): array
    {
        return array_values(array_filter(self::allCountries(), fn (string $code): bool => $this->allows($code)));
    }

    public static function connectionName(string $targetDb): ?string
    {
        return $targetDb === self::CENTRAL ? null : config('nawy.country_connection_prefix').$targetDb;
    }

    public static function db(string $targetDb): Connection
    {
        return DB::connection(self::connectionName($targetDb));
    }

    public function count(string $table, string $metric, int $by = 1): void
    {
        $this->metrics[$this->step][$table][$metric] = ($this->metrics[$this->step][$table][$metric] ?? 0) + $by;
    }

    public function sum(string $table, ?string $currency, mixed $amount): void
    {
        if ($amount === null || $currency === null || $currency === '') {
            return;
        }

        $current = $this->sums[$this->step][$table][$currency] ?? BigDecimal::zero();
        $this->sums[$this->step][$table][$currency] = $current->plus(BigDecimal::of((string) $amount));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function metricsFor(string $step): array
    {
        $out = [];

        foreach ($this->metrics[$step] ?? [] as $table => $counts) {
            ksort($counts);
            $out[$table] = $counts;
        }

        foreach ($this->sums[$step] ?? [] as $table => $currencies) {
            ksort($currencies);
            $out[$table]['sums'] = array_map(fn (BigDecimal $sum): string => (string) $sum, $currencies);
        }

        ksort($out);

        return $out;
    }
}
