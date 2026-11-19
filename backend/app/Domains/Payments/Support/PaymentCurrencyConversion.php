<?php

namespace App\Domains\Payments\Support;

use App\Domains\Finance\Models\ExchangeRate;
use App\Domains\Finance\Support\ExchangeRates\ExchangeRates;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Enums\RefundStatus;
use App\Domains\Payments\Exceptions\PaymentMethodUnavailable;
use App\Domains\Payments\Models\Payment;
use App\Shared\Money\MoneyFactory;
use Brick\Math\RoundingMode;
use Brick\Money\Money;

/**
 * Gateways that cannot settle the country currency (PayPal: AED, OMR, SAR…)
 * are charged in `nawy.payments.paypal.charge_currency` (USD) at the current
 * exchange rate. The payment keeps the order money in the country currency
 * exactly (`amount`, `captured_amount`, `refunded_amount`, ledger) and stores
 * what the provider was asked for (`charge_currency`, `charge_amount`) with
 * the rate. Provider amounts are translated back with that stored rate;
 * refunds are worked out with it too, and a refund of everything left
 * returns exactly the charge amount left.
 */
class PaymentCurrencyConversion
{
    public function __construct(private ExchangeRates $rates, private MoneyFactory $money) {}

    /**
     * The currency the provider must be charged in, or null when it takes the country currency.
     */
    public function chargeCurrencyFor(PaymentMethod $method, string $currency): ?string
    {
        if ($method !== PaymentMethod::PayPal) {
            return null;
        }

        $native = (array) config('nawy.payments.paypal.native_currencies', []);

        return in_array($currency, $native, true) && $this->money->zero($currency)->getCurrency()->getDefaultFractionDigits() <= 2
            ? null
            : (string) config('nawy.payments.paypal.charge_currency', 'USD');
    }

    public function rateFor(PaymentMethod $method, string $currency): ?ExchangeRate
    {
        $chargeCurrency = $this->chargeCurrencyFor($method, $currency);

        return $chargeCurrency === null ? null : $this->rates->current($currency, $chargeCurrency);
    }

    /**
     * Whether the method can be charged for this currency (natively or with a rate).
     */
    public function canCharge(PaymentMethod $method, string $currency): bool
    {
        return $this->chargeCurrencyFor($method, $currency) === null || $this->rateFor($method, $currency) !== null;
    }

    /**
     * What the customer will be charged by the provider, for the checkout method list.
     *
     * @return array{currency: string, amount: string, rate: string}|null
     */
    public function quote(PaymentMethod $method, Money $amount): ?array
    {
        $rate = $this->rateFor($method, $amount->getCurrency()->getCurrencyCode());

        return $rate === null ? null : [
            'currency' => $rate->quote_currency,
            'amount' => (string) $this->rates->convert($amount, $rate)->getAmount(),
            'rate' => rtrim(rtrim((string) $rate->rate, '0'), '.'),
        ];
    }

    /**
     * Fill the charge columns of a new payment when its gateway needs a conversion.
     *
     * @throws PaymentMethodUnavailable when no rate exists
     */
    public function apply(Payment $payment): void
    {
        if ($payment->charge_currency !== null) {
            return;
        }

        $chargeCurrency = $this->chargeCurrencyFor($payment->method, $payment->currency);

        if ($chargeCurrency === null) {
            return;
        }

        $rate = $this->rates->current($payment->currency, $chargeCurrency)
            ?? throw new PaymentMethodUnavailable(__(':method cannot charge :currency until an exchange rate to :charge exists.', [
                'method' => $payment->method->label(), 'currency' => $payment->currency, 'charge' => $chargeCurrency,
            ]));

        $payment->forceFill([
            'charge_currency' => $chargeCurrency,
            'charge_amount' => $this->money->toStorage($this->rates->convert($this->money->of((string) $payment->amount, $payment->currency), $rate)),
            'exchange_rate' => $rate->rate,
            'exchange_rate_id' => $rate->id,
        ])->save();
    }

    /**
     * The amount the provider is asked for.
     */
    public function gatewayAmount(Payment $payment): Money
    {
        return $payment->charge_currency === null
            ? $this->money->of((string) $payment->amount, $payment->currency)
            : $this->money->of((string) $payment->charge_amount, $payment->charge_currency);
    }

    /**
     * A provider amount (charge currency) in the country currency: the exact
     * order amount when the provider took exactly the charge amount, else
     * converted back with the stored rate (and so reviewed as a mismatch).
     */
    public function toLocal(Payment $payment, string $providerAmount): string
    {
        if ($payment->charge_currency === null) {
            return $providerAmount;
        }

        $reported = $this->money->of($providerAmount, $payment->charge_currency);

        if ($reported->isEqualTo($this->money->of((string) $payment->charge_amount, $payment->charge_currency))) {
            return (string) $this->money->of((string) $payment->amount, $payment->currency)->getAmount();
        }

        $local = $reported->getAmount()->dividedBy((string) $payment->exchange_rate, $this->money->zero($payment->currency)->getCurrency()->getDefaultFractionDigits(), RoundingMode::HalfUp);

        return (string) $local;
    }

    /**
     * The charge-currency amount to refund for `$local` (country currency):
     * `$local × stored rate`, half-up, at most what is left of the charge;
     * refunding everything still refundable returns exactly what is left.
     */
    public function refundCharge(Payment $payment, Money $local): ?Money
    {
        if ($payment->charge_currency === null) {
            return null;
        }

        $alreadyLocal = $this->money->of((string) $payment->refunded_amount, $payment->currency)
            ->plus($this->money->of((string) $payment->refunds()->where('status', RefundStatus::Pending)->sum('amount'), $payment->currency));
        $alreadyCharge = $this->money->of((string) $payment->refunds()->whereIn('status', [RefundStatus::Pending, RefundStatus::Completed])->sum('charge_amount'), $payment->charge_currency);
        $chargeLeft = $this->money->of((string) $payment->charge_amount, $payment->charge_currency)->minus($alreadyCharge);
        $localLeft = $this->money->of((string) $payment->captured_amount, $payment->currency)->minus($alreadyLocal);

        if ($local->isGreaterThanOrEqualTo($localLeft)) {
            return $chargeLeft;
        }

        $converted = Money::of($local->getAmount()->multipliedBy((string) $payment->exchange_rate), $payment->charge_currency, roundingMode: RoundingMode::HalfUp);

        return $converted->isGreaterThan($chargeLeft) ? $chargeLeft : $converted;
    }
}
