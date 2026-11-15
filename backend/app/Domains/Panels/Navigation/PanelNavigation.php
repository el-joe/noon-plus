<?php

namespace App\Domains\Panels\Navigation;

use App\Domains\Marketers\Support\MarketerWorkspace;
use App\Domains\Panels\Support\Panel;
use App\Domains\Vendors\Support\VendorWorkspace;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * Sidebar groups per panel, matching the Figma panel shells (QC-030,
 * QA-030). An item whose page is not built yet is shown disabled with a
 * "Soon" tag (never a `#` link); an item whose route requires a `can:`
 * permission the user lacks is hidden, and an empty group is dropped.
 */
class PanelNavigation
{
    /**
     * @return array<int, array{label: string, items: array<int, array{label: string, href: string|null, active: bool, soon: bool}>}>
     */
    public static function for(Panel $panel, string $activeRoute): array
    {
        $groups = match ($panel->key) {
            'admin' => [
                __('Overview') => ['dashboard' => __('Dashboard'), 'approvals' => __('Approvals queue'), 'activity' => __('Activity log')],
                __('Commerce') => ['catalog' => __('Catalog'), 'inventory' => __('Inventory & fulfilment'), 'orders' => __('Orders'), 'returns' => __('Returns'), 'refunds' => __('Refunds'), 'disputes' => __('Disputes'), 'warranty-claims' => __('Warranty claims'), 'reviews' => __('Reviews & Q&A'), 'users' => __('Users & vendors'), 'applications' => __('Applications')],
                __('Growth') => ['marketers' => __('Marketers'), 'marketers.campaigns' => __('Marketer campaigns'), 'marketers.settings' => __('Marketer settings'), 'marketing' => __('Ads review'), 'coupons' => __('Coupons'), 'marketing.coupon-participation' => __('Coupon participation'), 'gift-cards' => __('Gift cards & vouchers'), 'flash-sales' => __('Flash sales')],
                __('Verticals') => ['classifieds' => __('Classifieds review'), 'classifieds.reports' => __('Abuse reports'), 'real-estate' => __('Real estate'), 'travel' => __('Travel packages')],
                __('Operations') => ['contracts' => __('Contracts'), 'documents' => __('Document review'), 'join-options' => __('Join options'), 'delivery' => __('Delivery & logistics'), 'delivery.assignments' => __('Dispatch'), 'finance' => __('Finance'), 'finance.payments-review' => __('Payments under review'), 'membership' => __('Nawy One')],
                __('Content') => ['pages' => __('Pages & page builder'), 'app-contexts' => __('App contexts'), 'media' => __('Live & videos'), 'cms' => __('Blog & help centre'), 'notification-templates' => __('Notification templates'), 'advertise-requests' => __('Advertise requests')],
                __('Platform') => ['countries' => __('Countries & gateways'), 'settings' => __('Platform settings'), 'staff' => __('Staff'), 'roles' => __('Roles & permissions'), 'health' => __('System health')],
            ],
            'partner' => self::isClassifiedSeller() ? [
                __('Main') => ['dashboard' => __('Dashboard'), 'marketplace.ads' => __('My listings'), 'marketplace.ads.create' => __('Post a listing')],
                __('Buyers') => ['marketplace.inquiries' => __('Inquiries')],
                __('Promote') => ['advertising' => __('Ads & boosts')],
                __('Account') => ['store' => __('Profile'), 'contracts' => __('Contracts'), 'documents' => __('Documents'), 'support' => __('Support')],
            ] : [
                __('Main') => ['dashboard' => __('Dashboard'), 'orders' => __('Orders'), 'returns' => __('Returns'), 'disputes' => __('Disputes'), 'products' => __('Products'), 'inventory' => __('Inventory & warehouses')],
                __('Growth') => ['coupons' => __('Coupons'), 'flash-sales' => __('Flash sales'), 'campaigns' => __('Marketer campaigns'), 'advertising' => __('Advertising'), 'reviews' => __('Reviews')],
                __('Finance') => ['earnings' => __('Earnings'), 'statements' => __('Statements & invoices'), 'payouts' => __('Payouts')],
                __('Account') => ['store' => __('Store settings'), 'team' => __('Team & permissions'), 'contracts' => __('Contracts'), 'documents' => __('Documents'), 'support' => __('Support')],
            ],
            'marketer' => [
                __('Main') => ['dashboard' => __('Overview'), 'profile' => __('Profile & socials'), 'contract' => __('Marketer contract'), 'products' => __('My products')],
                __('Campaigns') => ['invitations' => __('Invitations'), 'marketplace' => __('Campaign marketplace'), 'campaigns' => __('Active campaigns'), 'links' => __('Links & coupons'), 'cross-promotion' => __('Cross-promotion')],
                __('Earnings') => ['wallet' => __('Wallet & withdrawals')],
            ] + (self::isBroker() ? [
                __('Broker') => ['broker.leads' => __('Leads pipeline'), 'broker.special-requests' => __('Special requests'), 'broker.properties' => __('Properties')],
            ] : []),
            'travel_agency' => [
                __('Main') => ['dashboard' => __('Dashboard'), 'packages' => __('Packages & trips'), 'departures' => __('Departures & capacity')],
                __('Bookings') => ['bookings' => __('Bookings'), 'inquiries' => __('Inquiries')],
                __('Reports') => ['reports' => __('Booking reports')],
                __('Account') => ['team' => __('Team & permissions')],
            ],
            'carrier' => [
                __('Operations') => ['dashboard' => __('Overview'), 'dispatch' => __('Live dispatch'), 'pickups' => __('Pickup requests'), 'shipments' => __('Shipments'), 'assignments' => __('Assignments'), 'exceptions' => __('Failed deliveries')],
                __('Team') => ['agents' => __('Drivers & supervisors')],
                __('Commercial') => ['cod' => __('COD & reconciliation'), 'claims' => __('Claims & scorecard')],
                __('Account') => ['company' => __('Company settings')],
            ],
            'delivery' => [
                __('Today') => ['dashboard' => __('My day'), 'assignments' => __('Assignments')],
                __('Money') => ['cod' => __('COD cash & settlements'), 'earnings' => __('Earnings')],
                __('Account') => ['profile' => __('Profile & vehicle'), 'documents' => __('Documents')],
            ],
            default => [],
        };

        $navigation = [];

        foreach ($groups as $label => $items) {
            $links = [];

            foreach ($items as $routeSuffix => $itemLabel) {
                $routeName = $panel->route($routeSuffix);

                if (! Route::has($routeName)) {
                    $links[] = ['label' => $itemLabel, 'href' => null, 'active' => false, 'soon' => true];

                    continue;
                }

                if (! self::allowed($routeName)) {
                    continue;
                }

                $links[] = [
                    'label' => $itemLabel,
                    'href' => route($routeName),
                    'active' => $routeName === $activeRoute || str_starts_with($activeRoute, $routeName.'.'),
                    'soon' => false,
                ];
            }

            if (collect($links)->contains(fn (array $link): bool => ! $link['soon'])) {
                $navigation[] = ['label' => $label, 'items' => $links];
            }
        }

        return $navigation;
    }

    /**
     * The signed-in user passes every `can:` middleware of the route.
     */
    private static function allowed(string $routeName): bool
    {
        $route = Route::getRoutes()->getByName($routeName);
        $user = Auth::user();

        foreach ($route?->gatherMiddleware() ?? [] as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'can:')) {
                $ability = explode(',', substr($middleware, 4))[0];

                if ($user === null || ! Gate::forUser($user)->allows($ability)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Seller Center shows the Marketplace layout (Figma 102:10964) to classified sellers.
     */
    private static function isClassifiedSeller(): bool
    {
        $workspace = app(VendorWorkspace::class);

        return $workspace->has() && $workspace->vendor()->type === 'classified';
    }

    private static function isBroker(): bool
    {
        $workspace = app(MarketerWorkspace::class);

        return $workspace->has() && in_array('broker', $workspace->marketer()->job_types ?? [], true);
    }
}
