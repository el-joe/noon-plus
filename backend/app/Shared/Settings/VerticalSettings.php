<?php

namespace App\Shared\Settings;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Settings\SettingsService;

/**
 * WP6 vertical settings for the current country: settings key
 * "{group}.{key}" (country override, then global) or the default in
 * `config('nawy.{group}.{key}')` — groups classified, property, travel and
 * advertising.
 */
class VerticalSettings
{
    public function __construct(private SettingsService $settings, private CountryContext $countryContext) {}

    public function get(string $key): mixed
    {
        return $this->settings->get($key, config("nawy.{$key}"), $this->countryContext->has() ? $this->countryContext->current() : null);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function string(string $key): string
    {
        return (string) $this->get($key);
    }
}
