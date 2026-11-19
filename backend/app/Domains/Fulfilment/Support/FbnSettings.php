<?php

namespace App\Domains\Fulfilment\Support;

use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Settings\SettingsService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Currency;

/**
 * FBN settings of the current country: setting `fbn.{key}` (country value,
 * then global) or `config('nawy.fbn.{key}')`. The fee table is
 * `fbn.fees`; without one the AED placeholders are converted with the
 * currency factor and rounded to the currency.
 */
class FbnSettings
{
    public const FEE_KEYS = [
        'outbound_price_threshold', 'outbound_extra_per_kg', 'storage_per_cubic_foot', 'long_term_per_cubic_foot',
        'unsellable_per_cubic_foot', 'removal_minimum', 'removal_per_unit', 'removal_per_kg', 'disposal_per_unit',
    ];

    public function __construct(private SettingsService $settings, private CountryContext $countryContext) {}

    public function int(string $key): int
    {
        return (int) $this->settings->get("fbn.{$key}", config("nawy.fbn.{$key}"), $this->country());
    }

    public function string(string $key): string
    {
        return (string) $this->settings->get("fbn.{$key}", config("nawy.fbn.{$key}"), $this->country());
    }

    /**
     * The fee table in the country's currency.
     *
     * @return array{outbound_price_threshold: string, outbound_tiers: array<int, array{max_grams: int, low: string, high: string}>, outbound_extra_per_kg: string, storage_per_cubic_foot: string, long_term_per_cubic_foot: string, unsellable_per_cubic_foot: string, removal_minimum: string, removal_per_unit: string, removal_per_kg: string, disposal_per_unit: string}
     */
    public function fees(): array
    {
        $stored = $this->settings->get('fbn.fees', null, $this->country());

        if (is_array($stored) && isset($stored['outbound_tiers'])) {
            return $this->normalise($stored);
        }

        return $this->defaultsFor($this->country()?->currency ?? 'AED');
    }

    /**
     * The placeholders converted to a currency.
     *
     * @return array<string, mixed>
     */
    public function defaultsFor(string $currency): array
    {
        $base = config('nawy.fbn.fees');
        $factor = (string) (config("nawy.fbn.currency_factor.{$currency}") ?? '1');
        $scale = Currency::of($currency)->getDefaultFractionDigits();
        $convert = fn (string $amount): string => (string) BigDecimal::of($amount)->multipliedBy($factor)->toScale($scale, RoundingMode::HalfUp);
        $fees = [];

        foreach (self::FEE_KEYS as $key) {
            $fees[$key] = $convert((string) $base[$key]);
        }

        $fees['outbound_tiers'] = array_map(fn (array $tier): array => [
            'max_grams' => (int) $tier['max_grams'], 'low' => $convert((string) $tier['low']), 'high' => $convert((string) $tier['high']),
        ], $base['outbound_tiers']);

        return $fees;
    }

    /**
     * Save the fee table for the current country.
     *
     * @param  array<string, mixed>  $fees
     */
    public function saveFees(array $fees, ?int $userId): void
    {
        $this->settings->set('fbn.fees', $this->normalise($fees), $this->country(), $userId);
    }

    /**
     * @param  array<string, mixed>  $fees
     * @return array<string, mixed>
     */
    private function normalise(array $fees): array
    {
        $clean = [];

        foreach (self::FEE_KEYS as $key) {
            $clean[$key] = (string) ($fees[$key] ?? '0');
        }

        $tiers = collect($fees['outbound_tiers'] ?? [])
            ->filter(fn ($tier): bool => is_array($tier) && (int) ($tier['max_grams'] ?? 0) > 0)
            ->map(fn (array $tier): array => ['max_grams' => (int) $tier['max_grams'], 'low' => (string) ($tier['low'] ?? '0'), 'high' => (string) ($tier['high'] ?? '0')])
            ->sortBy('max_grams')->values()->all();
        $clean['outbound_tiers'] = $tiers;

        return $clean;
    }

    private function country(): ?Country
    {
        return $this->countryContext->has() ? $this->countryContext->current() : null;
    }
}
