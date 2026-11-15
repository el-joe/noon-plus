<?php

namespace App\Domains\Catalog\Http\Resources;

use App\Domains\Countries\Support\CountryContext;
use App\Shared\Money\MoneyFactory;

/**
 * Formats a stored decimal as {amount, currency} in the current country's
 * currency, rounded to its minor unit.
 */
class MoneyResource
{
    /**
     * @return array{amount: string, currency: string}|null
     */
    public static function make(?string $amount): ?array
    {
        if ($amount === null) {
            return null;
        }

        $currency = app(CountryContext::class)->current()->currency;
        $money = app(MoneyFactory::class)->of($amount, $currency);

        return ['amount' => (string) $money->getAmount(), 'currency' => $currency];
    }

    /**
     * Same shape in an explicit currency (a stored row's own currency).
     *
     * @return array{amount: string, currency: string}|null
     */
    public static function of(string|int|null $amount, ?string $currency): ?array
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        $currency ??= app(CountryContext::class)->current()->currency;
        $money = app(MoneyFactory::class)->of((string) $amount, $currency);

        return ['amount' => (string) $money->getAmount(), 'currency' => $currency];
    }
}
