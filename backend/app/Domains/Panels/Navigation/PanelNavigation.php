<?php

namespace App\Domains\Panels\Navigation;

use App\Domains\Panels\Support\Panel;
use Illuminate\Support\Facades\Route;

/**
 * Sidebar groups per panel, matching the Figma panel shells. Items whose
 * route does not exist yet render as disabled links ("#").
 */
class PanelNavigation
{
    /**
     * @return array<int, array{label: string, items: array<int, array{label: string, href: string, active: bool}>}>
     */
    public static function for(Panel $panel, string $activeRoute): array
    {
        $groups = match ($panel->key) {
            'admin' => [
                __('Overview') => ['dashboard' => __('Dashboard'), 'approvals' => __('Approvals queue'), 'activity' => __('Activity log')],
                __('Commerce') => ['catalog' => __('Catalog'), 'inventory' => __('Inventory & fulfilment'), 'orders' => __('Orders & disputes'), 'users' => __('Users & vendors')],
                __('Growth') => ['marketers' => __('Marketers & campaigns'), 'marketing' => __('Marketing & ads'), 'coupons' => __('Coupons & flash sales')],
                __('Verticals') => ['classifieds' => __('Classifieds'), 'real-estate' => __('Real estate'), 'travel' => __('Travel')],
                __('Operations') => ['contracts' => __('Contracts'), 'delivery' => __('Delivery & logistics'), 'finance' => __('Finance')],
                __('Content') => ['pages' => __('Pages & page builder'), 'media' => __('Live & videos')],
                __('Platform') => ['countries' => __('Countries & gateways'), 'settings' => __('Platform settings'), 'health' => __('System health')],
            ],
            'partner' => [
                __('Main') => ['dashboard' => __('Dashboard'), 'orders' => __('Orders'), 'returns' => __('Returns'), 'products' => __('Products'), 'inventory' => __('Inventory & warehouses')],
                __('Growth') => ['coupons' => __('Coupons'), 'flash-sales' => __('Flash sales'), 'advertising' => __('Advertising'), 'reviews' => __('Reviews')],
                __('Finance') => ['earnings' => __('Earnings'), 'statements' => __('Statements & invoices'), 'payouts' => __('Payouts')],
                __('Account') => ['store' => __('Store settings'), 'team' => __('Team & permissions'), 'documents' => __('Documents'), 'support' => __('Support')],
            ],
            'marketer' => [
                __('Main') => ['dashboard' => __('Overview'), 'profile' => __('Profile & socials'), 'products' => __('My products')],
                __('Campaigns') => ['marketplace' => __('Campaign marketplace'), 'campaigns' => __('Active campaigns'), 'links' => __('Links & coupons'), 'cross-promotion' => __('Cross-promotion')],
                __('Earnings') => ['sales' => __('Sales & conversions'), 'wallet' => __('Wallet & withdrawals')],
            ],
            'travel_agency' => [
                __('Main') => ['dashboard' => __('Dashboard'), 'packages' => __('Packages & trips'), 'departures' => __('Departures & capacity')],
                __('Bookings') => ['bookings' => __('Bookings'), 'travellers' => __('Travellers'), 'refunds' => __('Refunds')],
                __('Finance') => ['earnings' => __('Earnings'), 'reports' => __('Reports')],
            ],
            'carrier' => [
                __('Operations') => ['dashboard' => __('Overview'), 'shipments' => __('Shipments'), 'pickups' => __('Pickup requests'), 'dispatch' => __('Live dispatch'), 'exceptions' => __('Exceptions')],
                __('Commercial') => ['zones' => __('Service zones & pricing'), 'cod' => __('COD & reconciliation'), 'settlements' => __('Settlements')],
            ],
            'delivery' => [
                __('Today') => ['dashboard' => __('My day'), 'assignments' => __('Assignments'), 'map' => __('Route map')],
                __('Money') => ['cod' => __('COD cash in hand'), 'earnings' => __('Earnings'), 'wallet' => __('Wallet & payouts')],
                __('Account') => ['profile' => __('Profile & vehicle'), 'documents' => __('Documents')],
            ],
            default => [],
        };

        $navigation = [];

        foreach ($groups as $label => $items) {
            $links = [];

            foreach ($items as $routeSuffix => $itemLabel) {
                $routeName = $panel->route($routeSuffix);
                $links[] = [
                    'label' => $itemLabel,
                    'href' => Route::has($routeName) ? route($routeName) : '#',
                    'active' => $routeName === $activeRoute,
                ];
            }

            $navigation[] = ['label' => $label, 'items' => $links];
        }

        return $navigation;
    }
}
