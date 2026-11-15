<?php

namespace App\Domains\Payments\Support;

use App\Domains\Countries\Models\Country;
use App\Domains\Finance\Support\Wallets;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Models\CountryGatewayConfig;
use App\Domains\Payments\Support\Gateways\StripeGateway;
use App\Domains\Settings\SettingsService;
use App\Shared\Money\MoneyFactory;
use Brick\Money\Money;
use Illuminate\Support\Collection;

/**
 * The payment methods a customer may use for an amount in a country
 * (ADR 6, FR-CHK-3): enabled gateways whose currencies include the country
 * currency and whose min/max fit the amount, plus COD (setting
 * `cod.enabled`, `cod.max_order_amount`, fee `checkout.cod_fee`). The
 * recommended gateway comes first, then the gateway sort order, then Nawy
 * Credits (only for a signed-in customer whose spendable + promotional
 * credits cover the amount, setting `wallet.payments_enabled`), COD last.
 * Gift cards are redeemed into credits first, so they are not a method.
 */
class PaymentMethodCatalog
{
    public function __construct(private SettingsService $settings, private MoneyFactory $money, private Wallets $wallets) {}

    /**
     * @return Collection<int, array{method: PaymentMethod, config: CountryGatewayConfig|null, recommended: bool, fee: Money|null}>
     */
    public function available(Country $country, Money $amount, ?int $customerUserId = null): Collection
    {
        $gateways = CountryGatewayConfig::query()
            ->where('country_id', $country->id)
            ->where('is_enabled', true)
            ->orderByDesc('is_recommended')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (CountryGatewayConfig $config): bool => $this->accepts($config, $amount))
            ->map(fn (CountryGatewayConfig $config): array => ['method' => $config->gateway, 'config' => $config, 'recommended' => $config->is_recommended, 'fee' => null])
            ->values();

        if ($customerUserId !== null && (bool) $this->settings->get('wallet.payments_enabled', true, $country)
            && $amount->isPositive() && $this->wallets->available($customerUserId, $amount->getCurrency()->getCurrencyCode())->isGreaterThanOrEqualTo($amount)) {
            $gateways->push(['method' => PaymentMethod::Wallet, 'config' => null, 'recommended' => false, 'fee' => null]);
        }

        if ($this->codAvailable($country, $amount)) {
            $gateways->push(['method' => PaymentMethod::Cod, 'config' => null, 'recommended' => false, 'fee' => $this->codFee($country)]);
        }

        return $gateways;
    }

    /**
     * The method for a requested code, if it can pay this amount; "card"
     * means the country's recommended gateway (or the first card gateway when
     * the recommended one cannot take this amount).
     *
     * @return array{method: PaymentMethod, config: CountryGatewayConfig|null, recommended: bool, fee: Money|null}|null
     */
    public function find(Country $country, string $code, Money $amount, ?int $customerUserId = null): ?array
    {
        $available = $this->available($country, $amount, $customerUserId);

        if ($code === 'card') {
            return $available->first(fn (array $option): bool => $option['recommended'])
                ?? $available->first(fn (array $option): bool => $option['method']->type() === 'card');
        }

        return $available->first(fn (array $option): bool => $option['method']->value === $code);
    }

    /**
     * Gateway config of a country (enabled or not).
     */
    public function config(Country $country, PaymentMethod $method): ?CountryGatewayConfig
    {
        return CountryGatewayConfig::query()->where('country_id', $country->id)->where('gateway', $method)->first();
    }

    public function codFee(Country $country): Money
    {
        $default = config("nawy.checkout.defaults.{$country->code}.cod_fee", '0');

        return $this->money->of((string) $this->settings->get('checkout.cod_fee', $default, $country), $country->currency);
    }

    private function accepts(CountryGatewayConfig $config, Money $amount): bool
    {
        $currency = $amount->getCurrency()->getCurrencyCode();

        return in_array($currency, $config->currencies ?? [], true)
            && ($config->min_amount === null || $amount->isGreaterThanOrEqualTo($this->money->of((string) $config->min_amount, $currency)))
            && ($config->max_amount === null || $amount->isLessThanOrEqualTo($this->money->of((string) $config->max_amount, $currency)))
            && match ($config->gateway) {
                PaymentMethod::Stripe => StripeGateway::acceptsAmount($amount),
                PaymentMethod::PayPal => $amount->getCurrency()->getDefaultFractionDigits() <= 2,
                PaymentMethod::Thawani => $currency === 'OMR',
                default => true,
            };
    }

    private function codAvailable(Country $country, Money $amount): bool
    {
        if (! (bool) $this->settings->get('cod.enabled', true, $country)) {
            return false;
        }

        $max = $this->settings->get('cod.max_order_amount', null, $country);

        return $max === null || $amount->isLessThanOrEqualTo($this->money->of((string) $max, $country->currency));
    }
}
