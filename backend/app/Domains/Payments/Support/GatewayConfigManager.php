<?php

namespace App\Domains\Payments\Support;

use App\Domains\Countries\Models\Country;
use App\Domains\Payments\Enums\GatewayMode;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Models\CountryGatewayConfig;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin changes to a country's gateways (ADR 6) with the rule "exactly one
 * recommended gateway per country": the recommendation is moved, never
 * removed; the recommended gateway cannot be disabled; the first gateway
 * of a country becomes the recommended one. A unique stored column backs
 * this up in the database. Credentials are write-only: a blank field keeps
 * the saved value, and only the names of changed fields are audited.
 */
class GatewayConfigManager
{
    public function add(Country $country, PaymentMethod $method, User $by): CountryGatewayConfig
    {
        if (! $method->isGateway() || CountryGatewayConfig::query()->where('country_id', $country->id)->where('gateway', $method)->exists()) {
            throw ValidationException::withMessages(['gateway' => __('This gateway is already set up for :country.', ['country' => $country->name_en])]);
        }

        $isFirst = ! CountryGatewayConfig::query()->where('country_id', $country->id)->exists();

        return CountryGatewayConfig::query()->create([
            'country_id' => $country->id,
            'gateway' => $method,
            'is_enabled' => $isFirst,
            'mode' => GatewayMode::Sandbox,
            'currencies' => [$country->currency],
            'sort_order' => (int) CountryGatewayConfig::query()->where('country_id', $country->id)->max('sort_order') + 1,
            'is_recommended' => $isFirst,
            'updated_by' => $by->id,
        ]);
    }

    /**
     * @param  array{is_enabled: bool, mode: string, currencies: array<int, string>, min_amount: ?string, max_amount: ?string, sort_order: int, fee_rule: ?array<string, string>}  $settings
     * @param  array<string, string|null>  $credentials
     */
    public function update(CountryGatewayConfig $config, array $settings, array $credentials, User $by): CountryGatewayConfig
    {
        if ($config->is_recommended && ! $settings['is_enabled']) {
            throw ValidationException::withMessages(['is_enabled' => __('Make another gateway recommended before disabling this one.')]);
        }

        $changed = [];
        $saved = $config->credentials ?? [];

        foreach ($config->gateway->credentialFields() as $field) {
            $value = trim((string) ($credentials[$field] ?? ''));

            if ($value !== '' && ($saved[$field] ?? null) !== $value) {
                $saved[$field] = $value;
                $changed[] = $field;
            }
        }

        $config->fill($settings + ['updated_by' => $by->id]);

        if ($changed !== []) {
            $config->credentials = $saved;
        }

        $config->save();

        if ($changed !== []) {
            activity('payment_gateways')
                ->performedOn($config)
                ->causedBy($by)
                ->event('credentials_updated')
                ->withProperties(['fields' => $changed])
                ->log('credentials updated');
        }

        return $config;
    }

    public function makeRecommended(CountryGatewayConfig $config, User $by): void
    {
        if (! $config->is_enabled) {
            throw ValidationException::withMessages(['is_recommended' => __('Only an enabled gateway can be recommended.')]);
        }

        DB::connection($config->getConnectionName())->transaction(function () use ($config, $by): void {
            CountryGatewayConfig::query()
                ->where('country_id', $config->country_id)
                ->where('is_recommended', true)
                ->whereKeyNot($config->id)
                ->lockForUpdate()
                ->get()
                ->each(fn (CountryGatewayConfig $other) => $other->update(['is_recommended' => false, 'updated_by' => $by->id]));

            $config->update(['is_recommended' => true, 'updated_by' => $by->id]);
        });
    }
}
