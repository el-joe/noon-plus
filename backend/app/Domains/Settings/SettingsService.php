<?php

namespace App\Domains\Settings;

use App\Domains\Countries\Models\Country;
use Illuminate\Support\Facades\Cache;

/**
 * Reads and writes settings. A country value overrides the global value;
 * the caller's default is used when neither exists.
 */
class SettingsService
{
    private const CACHE_SECONDS = 3600;

    public function get(string $key, mixed $default = null, ?Country $country = null): mixed
    {
        if ($country !== null) {
            $countryValue = $this->stored($key, $country->id);

            if ($countryValue !== null) {
                return $countryValue['value'];
            }
        }

        $globalValue = $this->stored($key, null);

        return $globalValue === null ? $default : $globalValue['value'];
    }

    public function set(string $key, mixed $value, ?Country $country = null, ?int $updatedBy = null): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $key, 'country_id' => $country?->id],
            ['value' => $value, 'updated_by' => $updatedBy],
        );

        Cache::forget($this->cacheKey($key, $country?->id));
    }

    /**
     * @return array{value: mixed}|null
     */
    private function stored(string $key, ?int $countryId): ?array
    {
        return Cache::remember($this->cacheKey($key, $countryId), self::CACHE_SECONDS, function () use ($key, $countryId): ?array {
            $setting = Setting::query()->where('key', $key)->where('country_id', $countryId)->first();

            return $setting === null ? null : ['value' => $setting->value];
        });
    }

    private function cacheKey(string $key, ?int $countryId): string
    {
        return 'settings:'.($countryId ?? 'global').':'.$key;
    }
}
