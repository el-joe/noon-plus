<?php

namespace App\Domains\Migration\Steps;

use App\Domains\Migration\Support\Archiver;
use App\Domains\Migration\Support\CountryResolver;
use App\Domains\Migration\Support\IdMap;
use App\Domains\Migration\Support\LegacySource;
use App\Domains\Migration\Support\MigrationContext;
use App\Domains\Migration\Support\Quarantine;
use App\Domains\Migration\Support\TableRules;
use App\Domains\Migration\Support\TargetWriter;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use LogicException;

/**
 * One domain of the legacy migration (plan §3). Runs every table the rules
 * give this step: `map`/`verify` tables through a `migrate{Table}` handler of
 * the subclass, `archive` tables into `legacy_records` of their country,
 * `fold` and `drop` tables are only counted.
 */
abstract class Step
{
    public function __construct(
        protected MigrationContext $context,
        protected LegacySource $source,
        protected IdMap $ids,
        protected TargetWriter $writer,
        protected Quarantine $quarantine,
        protected Archiver $archiver,
        protected CountryResolver $countries,
    ) {}

    abstract public function name(): string;

    /**
     * Tables whose handlers must run first, in this order.
     *
     * @return array<int, string>
     */
    protected function order(): array
    {
        return [];
    }

    public function run(): void
    {
        $this->context->step = $this->name();
        $rules = TableRules::forStep($this->name());

        foreach (array_unique([...$this->order(), ...array_keys($rules)]) as $table) {
            $rule = $rules[$table] ?? null;

            if ($rule === null || $this->context->skipsTable($table)) {
                continue;
            }

            if (! $this->source->hasTable($table)) {
                $this->context->count($table, 'absent');

                continue;
            }

            match ($rule['disposition']) {
                'map', 'verify' => $this->handle($table),
                'archive' => $this->archiveTable($table, $rule),
                'fold' => $this->countOnly($table, 'folded'),
                'drop' => $this->countOnly($table, 'dropped'),
            };

            $this->quarantine->settle($table);
        }
    }

    /**
     * @return LazyCollection<int, array<string, mixed>>
     */
    protected function rows(string $table): LazyCollection
    {
        $only = $this->context->only !== null && $this->context->only['table'] === $table ? $this->context->only['id'] : null;

        return $this->source->rows($table, $this->context->since, $only)->tapEach(fn () => $this->context->count($table, 'read'));
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    protected function archiveTable(string $table, array $rule): void
    {
        foreach ($this->rows($table) as $row) {
            $this->archiveRow($table, $row, $rule['rule'], $rule['scope'] === 'central' ? null : $this->countries->resolveRow($table, $row), 'archive');
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{codes: array<int, string>, reason: string|null}|null  $resolution  null = central
     */
    protected function archiveRow(string $table, array $row, string $rule, ?array $resolution, string $reason): void
    {
        $key = $this->source->rowKey($table, $row);

        if ($resolution === null || $resolution['reason'] === 'central') {
            $this->archiver->archive($table, $key, $row, MigrationContext::CENTRAL, $rule, $reason);

            return;
        }

        $codes = $resolution['codes'];

        if ($codes === []) {
            $this->quarantine->put($table, $key, $this->reasonCode($resolution['reason']), $resolution['reason'], null, $row);

            return;
        }

        if (count($codes) > 1) {
            $this->quarantine->put($table, $key, 'country_ambiguous', 'owner is active in '.implode(', ', $codes), null, $row);

            return;
        }

        if (! $this->context->allows($codes[0])) {
            $this->context->count($table, 'skipped_other_country');

            return;
        }

        $this->archiver->archive($table, $key, $row, $codes[0], $rule, $reason);
    }

    protected function countOnly(string $table, string $metric): void
    {
        $this->context->count($table, $metric, $this->source->count($table));
    }

    protected function reasonCode(?string $reason): string
    {
        return Str::before((string) $reason, ':') ?: 'unresolved';
    }

    /**
     * The single country of a row, or null after quarantining it.
     *
     * @param  array<string, mixed>  $row
     */
    protected function singleCountry(string $table, array $row): ?string
    {
        $resolution = $this->countries->resolveRow($table, $row);
        $key = $this->source->rowKey($table, $row);

        if ($resolution['codes'] === []) {
            $reason = $resolution['reason'] === 'central' ? 'country_unresolved: global row (no country) where a country is required' : $resolution['reason'];
            $this->quarantine->put($table, $key, $this->reasonCode($reason), $reason, null, $row);

            return null;
        }

        if (count($resolution['codes']) > 1) {
            $this->quarantine->put($table, $key, 'country_ambiguous', 'resolves to '.implode(', ', $resolution['codes']), null, $row);

            return null;
        }

        if (! $this->context->allows($resolution['codes'][0])) {
            $this->context->count($table, 'skipped_other_country');

            return null;
        }

        return $resolution['codes'][0];
    }

    /**
     * Legacy timestamps carried over (history is a fact).
     *
     * @param  array<string, mixed>  $row
     * @return array{created_at: mixed, updated_at: mixed}
     */
    protected function timestamps(array $row): array
    {
        $created = $row['created_at'] ?? null;

        return ['created_at' => $created ?? now(), 'updated_at' => $row['updated_at'] ?? $created ?? now()];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function bilingual(array $row, string $field, string $locale): string
    {
        $other = $locale === 'en' ? 'ar' : 'en';

        return (string) ($row["{$field}_{$locale}"] ?? null ?: $row["{$field}_{$other}"] ?? null ?: $row[$field] ?? '');
    }

    /**
     * A legacy decimal as a bcmath operand (NULL = 0).
     */
    protected static function decimal(mixed $value): string
    {
        return $value === null || $value === '' ? '0' : (string) $value;
    }

    /**
     * The new user id of a legacy login row (any identity table), or null.
     */
    protected function userOf(string $legacyTable, ?string $legacyId): ?int
    {
        return $legacyId === null || $legacyId === '' ? null : $this->ids->find($legacyTable, $legacyId, MigrationContext::CENTRAL, 'users');
    }

    /**
     * A mapped row whose legacy fields are not all carried by the new
     * columns (signature data, WhatsApp delivery state, fee breakdowns…)
     * also keeps its full legacy row in `legacy_records` of the same
     * country — nothing is dropped, and support can read it in Admin →
     * Data migration → Legacy records.
     *
     * @param  array<string, mixed>  $row
     */
    protected function keepLegacyRow(string $table, string $sourceId, array $row, string $code): void
    {
        $this->archiver->archive($table, $sourceId, $row, $code, (string) (TableRules::for($table)['rule'] ?? 'R2'), 'mapped_with_legacy_fields');
    }

    private function handle(string $table): void
    {
        $method = 'migrate'.Str::studly($table);

        if (! method_exists($this, $method)) {
            throw new LogicException(static::class." has no handler {$method} for mapped table {$table}.");
        }

        $this->{$method}($table);
    }
}
