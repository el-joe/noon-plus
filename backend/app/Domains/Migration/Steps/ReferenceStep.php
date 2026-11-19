<?php

namespace App\Domains\Migration\Steps;

use App\Domains\Migration\Support\MigrationContext;
use App\Domains\Migration\Support\SettingKeyMap;
use Illuminate\Support\Facades\DB;

/**
 * Reference data (plan §3 step 1, rule R6): legacy countries and currencies
 * are verified against config/nawy.php (the new source of truth), settings
 * are mapped key by key (SettingKeyMap); roles, permissions, cities and the
 * activity log are archived centrally; transient tables (R8) are dropped.
 */
class ReferenceStep extends Step
{
    public function name(): string
    {
        return 'reference';
    }

    protected function order(): array
    {
        return ['countries', 'currencies', 'settings'];
    }

    protected function migrateCountries(string $table): void
    {
        $central = DB::table('countries')->pluck('id', 'code');

        foreach ($this->rows($table) as $row) {
            $code = strtolower((string) $row['site_code']);
            $config = config("nawy.countries.{$code}");

            if ($config === null || ! isset($central[$code])) {
                $this->quarantine->put($table, (string) $row['id'], 'country_not_launched', "site code {$code} is not one of the 8 launched countries", null, $row);

                continue;
            }

            if (strtoupper((string) $row['currency_code']) !== $config['currency']) {
                $this->quarantine->put($table, (string) $row['id'], 'country_config_mismatch', "currency {$row['currency_code']} ≠ {$config['currency']}", $code, $row);

                continue;
            }

            $this->writer->link($table, (string) $row['id'], MigrationContext::CENTRAL, 'countries', (int) $central[$code], 'linked');
        }
    }

    protected function migrateCurrencies(string $table): void
    {
        $launched = array_column(config('nawy.countries'), 'currency');

        foreach ($this->rows($table) as $row) {
            $this->context->count($table, in_array($row['code'], $launched, true) ? 'verified' : 'not_launched');
            $this->archiver->archive($table, (string) $row['code'], $row, MigrationContext::CENTRAL, 'R6', in_array($row['code'], $launched, true) ? 'verified_against_config' : 'currency_not_launched');
        }
    }

    protected function migrateSettings(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $key = (string) $row['key'];
            $target = SettingKeyMap::target($key);

            if ($target === SettingKeyMap::SECRET) {
                $this->context->count($table, 'secret_not_copied');
                $this->archiver->archive($table, (string) $row['id'], ['value' => '[redacted]'] + $row, MigrationContext::CENTRAL, 'R6', 'secret_reentered_in_admin');

                continue;
            }

            if ($target === null) {
                $this->archiver->archive($table, (string) $row['id'], $row, MigrationContext::CENTRAL, 'R6', 'unknown_setting_key');

                continue;
            }

            $value = json_decode((string) $row['value'], true);
            $existing = DB::table('settings')->where('key', $target)->whereNull('country_id')->first();
            $mapped = $this->ids->find($table, (string) $row['id'], MigrationContext::CENTRAL, 'settings');

            if ($existing !== null && $mapped === null) {
                $this->writer->link($table, (string) $row['id'], MigrationContext::CENTRAL, 'settings', (int) $existing->id, 'linked');

                continue;
            }

            $this->writer->upsert($table, (string) $row['id'], MigrationContext::CENTRAL, 'settings', [
                'key' => $target,
                'country_id' => null,
                'value' => json_encode($value, JSON_UNESCAPED_UNICODE),
                'updated_by' => null,
                'created_at' => $row['updated_at'] ?? now(),
                'updated_at' => $row['updated_at'] ?? now(),
            ]);
        }
    }
}
