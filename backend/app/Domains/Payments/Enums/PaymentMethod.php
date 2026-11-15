<?php

namespace App\Domains\Payments\Enums;

/**
 * Every way a customer can pay (ADR 6, FR-CHK-3): the six external gateways
 * (`country_gateway_configs`) and the internal methods COD, wallet and gift
 * card. The value is stored in `orders.payment_method` and `payments.method`.
 */
enum PaymentMethod: string
{
    case Thawani = 'thawani';
    case Stripe = 'stripe';
    case PayPal = 'paypal';
    case PayTabs = 'paytabs';
    case Tabby = 'tabby';
    case Tamara = 'tamara';
    case Cod = 'cod';
    case Wallet = 'wallet';
    case GiftCard = 'gift_card';

    /**
     * @return array<int, self>
     */
    public static function gateways(): array
    {
        return array_values(array_filter(self::cases(), fn (self $method): bool => $method->isGateway()));
    }

    public function isGateway(): bool
    {
        return ! in_array($this, [self::Cod, self::Wallet, self::GiftCard], true);
    }

    public function isBnpl(): bool
    {
        return in_array($this, [self::Tabby, self::Tamara], true);
    }

    /**
     * Customer-facing type used by the checkout payment step.
     */
    public function type(): string
    {
        return match (true) {
            $this->isBnpl() => 'bnpl',
            $this === self::PayPal => 'wallet_provider',
            $this->isGateway() => 'card',
            default => $this->value,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Thawani => 'Thawani Pay',
            self::Stripe => __('Credit / debit card'),
            self::PayPal => 'PayPal',
            self::PayTabs => 'PayTabs',
            self::Tabby => __('Tabby · pay in 4'),
            self::Tamara => __('Tamara · split in 3'),
            self::Cod => __('Cash on delivery'),
            self::Wallet => __('Nawy Wallet'),
            self::GiftCard => __('Gift card'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Thawani => __('Cards, Apple Pay · pay in OMR'),
            self::Stripe => __('Visa, Mastercard, Apple Pay · secured by Stripe'),
            self::PayPal => __('Pay with your PayPal account'),
            self::PayTabs => __('Cards, mada, Apple Pay'),
            self::Tabby => __('4 interest-free payments'),
            self::Tamara => __('3 interest-free payments'),
            self::Cod => __('Pay the courier in cash'),
            self::Wallet => __('Pay from your Nawy Wallet balance'),
            self::GiftCard => __('Redeem a gift card'),
        };
    }

    /**
     * Credential fields an admin fills for this gateway. They are stored
     * encrypted and never shown again (settings catalogue §5).
     *
     * @return array<int, string>
     */
    public function credentialFields(): array
    {
        return match ($this) {
            self::Thawani => ['secret_key', 'publishable_key', 'webhook_secret'],
            self::Stripe => ['secret_key', 'webhook_secret'],
            self::PayPal => ['client_id', 'client_secret', 'webhook_id'],
            self::PayTabs => ['profile_id', 'server_key', 'region'],
            self::Tabby => ['secret_key', 'merchant_code', 'webhook_secret'],
            self::Tamara => ['api_token', 'notification_token'],
            default => [],
        };
    }
}
