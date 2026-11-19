<?php

namespace App\Domains\Finance\Support\ExchangeRates;

use App\Domains\Countries\Models\Country;
use App\Domains\Finance\Models\ExchangeRate;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Central exchange rates (task: PayPal USD conversion). A pair's rate at a
 * time is the newest row whose `effective_at` is not after it. Admins add
 * rows (audited); an optional provider can add them on a schedule. Rates
 * are only used where a gateway cannot settle the country currency — order
 * money stays in the country currency and is never converted.
 */
class ExchangeRates
{
    public function __construct(private ExchangeRateProvider $provider) {}

    public function current(string $base, string $quote, ?DateTimeInterface $at = null): ?ExchangeRate
    {
        if (strtoupper($base) === strtoupper($quote)) {
            return null;
        }

        return ExchangeRate::query()->pair($base, $quote)
            ->where('effective_at', '<=', $at ?? now())
            ->orderByDesc('effective_at')->orderByDesc('id')
            ->first();
    }

    /**
     * `$money` in the rate's quote currency, half-up to its minor unit.
     */
    public function convert(Money $money, ExchangeRate $rate): Money
    {
        if ($money->getCurrency()->getCurrencyCode() !== $rate->base_currency) {
            throw new \InvalidArgumentException("Rate {$rate->base_currency}→{$rate->quote_currency} cannot convert {$money->getCurrency()->getCurrencyCode()}.");
        }

        return Money::of($money->getAmount()->multipliedBy((string) $rate->rate), $rate->quote_currency, roundingMode: RoundingMode::HalfUp);
    }

    public function record(string $base, string $quote, string $rate, DateTimeInterface $effectiveAt, string $source = ExchangeRate::SOURCE_MANUAL, ?string $note = null, ?User $by = null): ExchangeRate
    {
        $base = strtoupper(trim($base));
        $quote = strtoupper(trim($quote));

        if ($base === $quote) {
            throw ValidationException::withMessages(['quote_currency' => __('The two currencies must differ.')]);
        }

        if (! BigDecimal::of($rate)->isPositive()) {
            throw ValidationException::withMessages(['rate' => __('The rate must be greater than zero.')]);
        }

        $effectiveAt = CarbonImmutable::instance($effectiveAt)->startOfSecond();

        if (ExchangeRate::query()->pair($base, $quote)->where('effective_at', $effectiveAt)->exists()) {
            throw ValidationException::withMessages(['effective_at' => __('A rate for this pair already starts at that time.')]);
        }

        return ExchangeRate::query()->create([
            'base_currency' => $base,
            'quote_currency' => $quote,
            'rate' => (string) BigDecimal::of($rate)->toScale(10, RoundingMode::HalfUp),
            'effective_at' => $effectiveAt,
            'source' => $source,
            'note' => $note === null ? null : mb_substr($note, 0, 255),
            'created_by' => $by?->id,
        ]);
    }

    /**
     * Ask the configured provider for every active country currency → the
     * configured quote(s) and store the answers effective now.
     *
     * @return array<int, ExchangeRate>
     */
    public function sync(): array
    {
        $bases = $this->countryCurrencies()->all();
        $stored = [];

        foreach ($this->quotes() as $quote) {
            $wanted = array_values(array_diff($bases, [$quote]));

            foreach ($this->provider->fetch($wanted, $quote) as $base => $rate) {
                $stored[] = $this->record($base, $quote, $rate, now(), $this->provider->name());
            }
        }

        return $stored;
    }

    public function providerName(): string
    {
        return $this->provider->name();
    }

    /**
     * @return Collection<int, string>
     */
    public function countryCurrencies(): Collection
    {
        return Country::query()->active()->pluck('currency')->unique()->values();
    }

    /**
     * Currencies rates are kept against (the PayPal charge currency first).
     *
     * @return array<int, string>
     */
    public function quotes(): array
    {
        return array_values(array_unique(array_map('strtoupper', (array) config('nawy.exchange_rates.quotes', ['USD']))));
    }
}
