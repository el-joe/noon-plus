<?php

namespace App\Domains\Panels\Navigation;

use App\Domains\Marketers\Support\MarketerWorkspace;
use App\Domains\Panels\Support\Panel;
use App\Domains\Panels\Support\TenantPermissions;
use App\Domains\Vendors\Support\VendorWorkspace;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * Sidebar groups per panel, matching the Figma panel shells (QC-030,
 * QA-030). An item whose page is not built yet is shown disabled with a
 * "Soon" tag (never a `#` link); an item whose route requires a `can:`
 * permission, or a `tenant.can:` team-role group (QC-020), the user lacks
 * is hidden, and an empty group is dropped.
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
                __('Overview') => ['dashboard' => __('Dashboard'), 'approvals' => __('Approvals queue'), 'activity' => __('Activity log'), 'reports.cross-country' => __('Cross-country reports')],
                __('Commerce') => ['catalog' => __('Catalog'), 'inventory' => __('Inventory & fulfilment'), 'orders' => __('Orders'), 'returns' => __('Returns'), 'refunds' => __('Refunds'), 'disputes' => __('Disputes'), 'support' => __('Support tickets'), 'warranty-claims' => __('Warranty claims'), 'reviews' => __('Reviews & Q&A'), 'customers' => __('Customers'), 'users' => __('Users & vendors'), 'applications' => __('Applications'), 'product-requests' => __('Product requests'), 'change-requests' => __('Profile change requests')],
                __('Growth') => ['marketers' => __('Marketers'), 'marketers.campaigns' => __('Marketer campaigns'), 'marketers.settings' => __('Marketer settings'), 'marketers.celebrity-reports' => __('Celebrity monthly report'), 'marketing' => __('Ads review'), 'marketing.travel-promotions' => __('Travel promotions'), 'coupons' => __('Coupons'), 'marketing.coupon-participation' => __('Coupon participation'), 'gift-cards' => __('Gift cards & vouchers'), 'flash-sales' => __('Flash sales')],
                __('Verticals') => ['classifieds' => __('Classifieds review'), 'classifieds.reports' => __('Abuse reports'), 'classifieds.categories' => __('Classified categories'), 'real-estate' => __('Real estate'), 'travel' => __('Travel packages')],
                __('Operations') => ['contracts' => __('Contracts'), 'documents' => __('Document review'), 'join-options' => __('Join options'), 'portal-analytics' => __('Portal analytics'), 'delivery' => __('Delivery & logistics'), 'delivery.assignments' => __('Dispatch'), 'delivery.withdrawals' => __('Agent withdrawals'), 'finance' => __('Finance'), 'finance.ledger' => __('Ledger'), 'finance.payments-review' => __('Payments under review'), 'packaging' => __('Packaging'), 'membership' => __('Nawy One'), 'finance.subscriptions' => __('Vendor subscriptions'), 'finance.acquisition-commissions' => __('Acquisition commissions'), 'finance.my-acquisition-commissions' => __('My acquisition commissions')],
                __('Content') => ['pages' => __('Pages & page builder'), 'app-contexts' => __('App contexts'), 'media' => __('Live & videos'), 'cms' => __('Blog & help centre'), 'notification-templates' => __('Notification templates'), 'advertise-requests' => __('Advertise requests')],
                __('Platform') => ['countries' => __('Countries & gateways'), 'countries.exchange-rates' => __('Exchange rates'), 'countries.domains' => __('Domains'), 'settings' => __('Platform settings'), 'staff' => __('Staff'), 'roles' => __('Roles & permissions'), 'health' => __('System health'), 'email-log' => __('Email log')],
                __('Data migration') => ['migration.quarantine' => __('Quarantine queue'), 'migration.runs' => __('Migration runs'), 'migration.records' => __('Legacy records'), 'migration.redirects' => __('Legacy redirects'), 'migration.reconciliation' => __('Reconciliation')],
            ],
            'partner' => self::isClassifiedSeller() ? [
                __('Main') => ['dashboard' => __('Dashboard'), 'marketplace.ads' => __('My listings'), 'marketplace.ads.create' => __('Post a listing')],
                __('Buyers') => ['marketplace.inquiries' => __('Inquiries'), 'marketplace.requests' => __('Viewing & reserve requests')],
                __('Promote') => ['advertising' => __('Ads & boosts')],
                __('Account') => ['store' => __('Profile'), 'change-requests' => __('Change requests'), 'contracts' => __('Contracts'), 'documents' => __('Documents'), 'support' => __('Support')],
            ] : [
                __('Main') => ['dashboard' => __('Dashboard'), 'orders' => __('Orders'), 'returns' => __('Returns'), 'disputes' => __('Disputes'), 'products' => __('Products'), 'product-requests' => __('Product requests'), 'inventory' => __('Inventory & warehouses'), 'packaging' => __('Packaging supplies')],
                __('Growth') => ['coupons' => __('Coupons'), 'flash-sales' => __('Flash sales'), 'campaigns' => __('Marketer campaigns'), 'advertising' => __('Advertising'), 'reviews' => __('Reviews')],
                __('Finance') => ['earnings' => __('Earnings'), 'statements' => __('Statements & invoices'), 'payouts' => __('Payouts'), 'subscription' => __('Subscription')],
                __('Account') => ['store' => __('Store settings'), 'change-requests' => __('Change requests'), 'team' => __('Team & permissions'), 'contracts' => __('Contracts'), 'documents' => __('Documents'), 'support' => __('Support')],
            ],
            'marketer' => [
                __('Main') => ['dashboard' => __('Overview'), 'profile' => __('Profile & socials'), 'contract' => __('Marketer contract'), 'products' => __('My products'), 'product-requests' => __('Product requests')],
                __('Campaigns') => ['invitations' => __('Invitations'), 'marketplace' => __('Campaign marketplace'), 'campaigns' => __('Active campaigns'), 'links' => __('Links & coupons'), 'cross-promotion' => __('Cross-promotion'), 'flash-sales' => __('Flash sales'), 'advertising' => __('Advertising')],
                __('Earnings') => ['wallet' => __('Wallet & withdrawals'), 'reports' => __('Monthly reports')],
                __('Account') => ['support' => __('Support')],
            ] + (self::isBroker() ? [
                __('Broker') => ['broker.leads' => __('Leads pipeline'), 'broker.requests' => __('Viewing & reserve requests'), 'broker.special-requests' => __('Special requests'), 'broker.properties' => __('Properties')],
            ] : []),
            'travel_agency' => [
                __('Main') => ['dashboard' => __('Dashboard'), 'packages' => __('Packages & trips'), 'departures' => __('Departures & capacity')],
                __('Bookings') => ['bookings' => __('Bookings'), 'inquiries' => __('Inquiries')],
                __('Promote') => ['promotions' => __('Promotions'), 'promotions.create' => __('Promote a trip')],
                __('Reports') => ['reports' => __('Booking reports')],
                __('Account') => ['change-requests' => __('Change requests'), 'team' => __('Team & permissions'), 'support' => __('Support')],
            ],
            'carrier' => [
                __('Operations') => ['dashboard' => __('Overview'), 'dispatch' => __('Live dispatch'), 'pickups' => __('Pickup requests'), 'shipments' => __('Shipments'), 'assignments' => __('Assignments'), 'exceptions' => __('Failed deliveries')],
                __('Team') => ['agents' => __('Drivers & supervisors'), 'driver-documents' => __('Driver documents')],
                __('Commercial') => ['cod' => __('COD & reconciliation'), 'withdrawals' => __('Agent withdrawals'), 'claims' => __('Claims & scorecard')],
                __('Account') => ['company' => __('Company settings'), 'support' => __('Support')],
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
     * The signed-in user passes every `can:` and `tenant.can:` (read) middleware of the route.
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

            if (is_string($middleware) && str_starts_with($middleware, 'tenant.can:')) {
                [$panel, $group] = explode(',', substr($middleware, 11)) + [1 => ''];

                if (! app(TenantPermissions::class)->allows(request(), $panel, $group, false)) {
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
