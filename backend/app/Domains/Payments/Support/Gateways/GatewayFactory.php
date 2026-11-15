<?php

namespace App\Domains\Payments\Support\Gateways;

use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Exceptions\PaymentMethodUnavailable;
use App\Domains\Payments\Models\CountryGatewayConfig;

/**
 * Picks the strategy for a payment method (ADR 6). External gateways need
 * their country config; with the "fake" driver every external gateway is the
 * {@see FakeGateway}.
 */
class GatewayFactory
{
    public function make(PaymentMethod $method, ?CountryGatewayConfig $config = null): PaymentGateway
    {
        if (! $method->isGateway()) {
            return match ($method) {
                PaymentMethod::Cod => new CashOnDelivery,
                PaymentMethod::Wallet => app(WalletMethod::class),
                default => new GiftCardMethod,
            };
        }

        if ($config === null) {
            throw new PaymentMethodUnavailable(__(':method is not configured for this country.', ['method' => $method->label()]));
        }

        if (config('nawy.payments.driver') === 'fake') {
            return new FakeGateway($method, $config->credential('webhook_secret'));
        }

        return match ($method) {
            PaymentMethod::Thawani => new ThawaniGateway($config),
            PaymentMethod::Stripe => new StripeGateway($config),
            PaymentMethod::PayPal => new PayPalGateway($config),
            PaymentMethod::PayTabs => new PayTabsGateway($config),
            PaymentMethod::Tabby => new TabbyGateway($config),
            default => new TamaraGateway($config),
        };
    }
}
