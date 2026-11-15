<?php

namespace App\Domains\Content\Enums;

/**
 * Page templates an admin can build (page-builder-blocks.md §1) and their zones.
 */
enum PageTemplate: string
{
    case Home = 'home';
    case StoreLanding = 'store_landing';
    case Category = 'category';
    case Campaign = 'campaign';
    case BrandStore = 'brand_store';
    case SellerStore = 'seller_store';
    case MarketerStore = 'marketer_store';
    case Search = 'search';
    case VerticalLanding = 'vertical_landing';
    case AppContext = 'app_context';

    /**
     * @return array<int, string>
     */
    public function zones(): array
    {
        return match ($this) {
            self::Home, self::StoreLanding, self::AppContext => ['main'],
            self::Category => ['header', 'top', 'listing', 'bottom'],
            self::Campaign, self::BrandStore, self::SellerStore, self::MarketerStore => ['header', 'top', 'listing'],
            self::Search => ['listing'],
            self::VerticalLanding => ['main', 'listing'],
        };
    }

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }
}
