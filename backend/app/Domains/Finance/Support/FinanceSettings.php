<?php

namespace App\Domains\Finance\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Settings\SettingsService;

/**
 * Finance settings for the current country: the settings key (country
 * override, then global) or the default in `config('nawy.finance')`.
 */
class FinanceSettings
{
    public function __construct(private SettingsService $settings, private CountryContext $countryContext) {}

    public function get(string $settingKey, string $configKey): mixed
    {
        return $this->settings->get($settingKey, config("nawy.finance.{$configKey}"), $this->countryContext->has() ? $this->countryContext->current() : null);
    }
}
