<?php

namespace App\Domains\Finance\Support\ExchangeRates;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Open Exchange Rates (https://openexchangerates.org) — `GET /api/latest.json?app_id=…`
 * returns `rates` for 1 USD. Rates for another quote are derived through USD
 * (`base→quote = rates[quote] / rates[base]`), so the free plan (USD base only)
 * is enough. Needs `NAWY_EXCHANGE_RATES_APP_ID` (owner key).
 */
class OpenExchangeRatesProvider implements ExchangeRateProvider
{
    public function __construct(private ?string $appId, private int $timeoutSeconds = 15) {}

    public function name(): string
    {
        return 'openexchangerates';
    }

    public function fetch(array $bases, string $quote): array
    {
        if ($this->appId === null || $this->appId === '') {
            throw new RuntimeException('Set NAWY_EXCHANGE_RATES_APP_ID to use the openexchangerates driver.');
        }

        $response = Http::timeout($this->timeoutSeconds)->acceptJson()
            ->get('https://openexchangerates.org/api/latest.json', ['app_id' => $this->appId]);

        if (! $response->successful()) {
            throw new RuntimeException('Open Exchange Rates answered HTTP '.$response->status().'.');
        }

        $usdRates = (array) $response->json('rates', []);
        $quoteRate = $quote === 'USD' ? '1' : self::decimal($usdRates[$quote] ?? null);

        if ($quoteRate === null) {
            return [];
        }

        $rates = [];

        foreach ($bases as $base) {
            $baseRate = $base === 'USD' ? '1' : self::decimal($usdRates[$base] ?? null);

            if ($baseRate === null || BigDecimal::of($baseRate)->isZero()) {
                continue;
            }

            $rates[$base] = (string) BigDecimal::of($quoteRate)->dividedBy($baseRate, 10, RoundingMode::HalfUp);
        }

        return $rates;
    }

    /**
     * JSON numbers arrive as floats; never let PHP print them in E notation.
     */
    private static function decimal(mixed $value): ?string
    {
        return is_int($value) || is_float($value) ? rtrim(rtrim(sprintf('%.12F', $value), '0'), '.') : (is_string($value) && is_numeric($value) ? $value : null);
    }
}
