<?php

namespace App\Domains\Contracts\Enums;

/**
 * Contract kinds (MP §9, SRS §13.1). Each kind resolves its own template
 * per country; none falls back to another country.
 */
enum ContractKind: string
{
    case SellerPlatform = 'seller_platform';
    case SellerCategory = 'seller_category';
    case SellerMarketer = 'seller_marketer';
    case CustomerPurchase = 'customer_purchase';
    case MarketerOnboarding = 'marketer_onboarding';
    case Exclusive = 'exclusive';
    case TravelAgency = 'travel_agency';
    case Broker = 'broker';
    case ShippingCompany = 'shipping_company';
    case Trip = 'trip';

    public function label(): string
    {
        return match ($this) {
            self::SellerPlatform => __('Seller Agreement'),
            self::SellerCategory => __('Category / product contract'),
            self::SellerMarketer => __('Collaboration contract (seller ↔ marketer)'),
            self::CustomerPurchase => __('Customer purchase contract'),
            self::MarketerOnboarding => __('Marketer onboarding contract'),
            self::Exclusive => __('Exclusive contract'),
            self::TravelAgency => __('Travel agency agreement'),
            self::Broker => __('Broker agreement'),
            self::ShippingCompany => __('Shipping company agreement'),
            self::Trip => __('Trip contract (customer ↔ agency)'),
        };
    }

    /**
     * Kinds resolved through assignments to a category or product.
     */
    public function usesAssignments(): bool
    {
        return in_array($this, [self::SellerCategory, self::CustomerPurchase, self::Trip], true);
    }

    /**
     * Notice mode (continued use = consent) exists for the Seller Agreement only.
     */
    public function allowsNotice(): bool
    {
        return $this === self::SellerPlatform;
    }
}
