<?php

namespace App\Domains\Panels\Navigation;

use App\Domains\Identity\Support\AdminVendorScope;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\Listing;
use App\Domains\Marketers\Support\MarketerWorkspace;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Panels\Support\Panel;
use App\Domains\Panels\Support\TenantPermissions;
use App\Domains\Vendors\Support\VendorWorkspace;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * Sidebar of every panel: top-level links and collapsible, task-oriented
 * groups (docs/project/panel-navigation.md). An item whose route requires
 * a `can:` permission, or a `tenant.can:` team-role group (QC-020), the
 * user lacks is hidden, and a group left without items is dropped.
 *
 * Each entry: `type` link|group, `key`, `label`, `icon` (x-icon name),
 * `items` (links; a top-level link carries itself as its only item),
 * `active` (holds the current page) and `count` (sum of the item badges).
 */
class PanelNavigation
{
    /**
     * @return array<int, array{type: string, key: string, label: string, icon: string, active: bool, count: int|null, items: array<int, array{key: string, label: string, href: string, active: bool, soon: bool, count: int|null}>}>
     */
    public static function for(Panel $panel, string $activeRoute): array
    {
        $entries = [];

        foreach (self::structure($panel->key) as $entry) {
            $links = [];

            foreach ($entry['items'] as $routeSuffix => $itemLabel) {
                $routeName = $panel->route($routeSuffix);

                if (! Route::has($routeName) || ! self::allowed($routeName)) {
                    continue;
                }

                $links[] = [
                    'key' => $routeSuffix,
                    'label' => $itemLabel,
                    'href' => route($routeName),
                    'active' => $routeName === $activeRoute || str_starts_with($activeRoute, $routeName.'.'),
                    'soon' => false,
                    'count' => self::count($panel->key, $routeSuffix),
                ];
            }

            if ($links !== []) {
                $entries[] = ['entry' => $entry, 'links' => $links];
            }
        }

        // Only the most specific link is active (e.g. "Travel promotions" over "Ads review").
        $activeKey = collect($entries)->pluck('links')->flatten(1)->where('active', true)->sortByDesc(fn (array $link): int => strlen($link['key']))->first()['key'] ?? null;
        $navigation = [];

        foreach ($entries as ['entry' => $entry, 'links' => $links]) {
            $links = array_map(fn (array $link): array => ['active' => $link['key'] === $activeKey] + $link, $links);
            $counts = array_filter(array_column($links, 'count'));

            $navigation[] = [
                'type' => $entry['type'],
                'key' => $entry['key'],
                'label' => $entry['label'],
                'icon' => $entry['icon'],
                'active' => in_array(true, array_column($links, 'active'), true),
                'count' => $counts === [] ? null : array_sum($counts),
                'items' => $links,
            ];
        }

        return $navigation;
    }

    /**
     * The sidebar entries of a panel, in display order.
     *
     * @return array<int, array{type: string, key: string, label: string, icon: string, items: array<string, string>}>
     */
    public static function structure(string $panelKey): array
    {
        return match ($panelKey) {
            'admin' => [
                self::link('dashboard', __('Dashboard'), 'layout-dashboard'),
                self::link('approvals', __('Approvals queue'), 'clipboard-check'),
                self::group('orders', __('Orders & fulfilment'), 'shopping-bag', [
                    'orders' => __('Orders'),
                    'returns' => __('Returns'),
                    'refunds' => __('Refunds'),
                    'disputes' => __('Disputes'),
                    'warranty-claims' => __('Warranty claims'),
                ]),
                self::group('catalog', __('Catalog'), 'tag', [
                    'catalog' => __('Catalog'),
                    'product-requests' => __('Product requests'),
                ]),
                self::group('sellers', __('Sellers & partners'), 'store', [
                    'users' => __('Users & vendors'),
                    'applications' => __('Applications'),
                    'change-requests' => __('Profile change requests'),
                    'contracts' => __('Contracts'),
                    'documents' => __('Document review'),
                    'join-options' => __('Join options'),
                ]),
                self::group('customers', __('Customers & support'), 'users', [
                    'customers' => __('Customers'),
                    'support' => __('Support tickets'),
                    'reviews' => __('Reviews & Q&A'),
                    'membership' => __('Nawy One'),
                ]),
                self::group('delivery', __('Delivery'), 'truck', [
                    'delivery' => __('Delivery & logistics'),
                    'delivery.assignments' => __('Dispatch'),
                    'delivery.withdrawals' => __('Agent withdrawals'),
                ]),
                self::group('inventory', __('Inventory & FBN'), 'warehouse', [
                    'inventory' => __('Inventory & fulfilment'),
                    'packaging' => __('Packaging'),
                ]),
                self::group('marketing', __('Marketing & promotions'), 'megaphone', [
                    'marketing' => __('Ads review'),
                    'coupons' => __('Coupons'),
                    'marketing.coupon-participation' => __('Coupon participation'),
                    'flash-sales' => __('Flash sales'),
                    'gift-cards' => __('Gift cards & vouchers'),
                    'advertise-requests' => __('Advertise requests'),
                ]),
                self::group('creators', __('Creators'), 'sparkles', [
                    'marketers' => __('Marketers'),
                    'marketers.campaigns' => __('Marketer campaigns'),
                    'marketers.celebrity-reports' => __('Celebrity monthly report'),
                    'marketers.settings' => __('Marketer settings'),
                ]),
                self::group('souq', __('Open Souq & classifieds'), 'layout-grid', [
                    'classifieds' => __('Classifieds review'),
                    'classifieds.reports' => __('Abuse reports'),
                    'classifieds.categories' => __('Classified categories'),
                    'real-estate' => __('Real estate'),
                ]),
                self::group('travel', __('Travel'), 'plane', [
                    'travel' => __('Travel packages'),
                    'marketing.travel-promotions' => __('Travel promotions'),
                ]),
                self::group('content', __('Content & pages'), 'file-text', [
                    'pages' => __('Pages & page builder'),
                    'cms' => __('Blog & help centre'),
                    'media' => __('Live & videos'),
                    'app-contexts' => __('App contexts'),
                    'notification-templates' => __('Notification templates'),
                ]),
                self::group('finance', __('Finance'), 'banknote', [
                    'finance' => __('Finance'),
                    'finance.ledger' => __('Ledger'),
                    'finance.payments-review' => __('Payments under review'),
                    'finance.subscriptions' => __('Vendor subscriptions'),
                    'finance.acquisition-commissions' => __('Acquisition commissions'),
                    'finance.my-acquisition-commissions' => __('My acquisition commissions'),
                ]),
                self::group('reports', __('Reports & analytics'), 'bar-chart', [
                    'reports.cross-country' => __('Cross-country reports'),
                    'portal-analytics' => __('Portal analytics'),
                ]),
                self::group('settings', __('Countries & settings'), 'globe', [
                    'countries' => __('Countries & gateways'),
                    'countries.exchange-rates' => __('Exchange rates'),
                    'countries.domains' => __('Domains'),
                    'settings' => __('Platform settings'),
                ]),
                self::group('staff', __('Staff & security'), 'shield', [
                    'staff' => __('Staff'),
                    'roles' => __('Roles & permissions'),
                    'activity' => __('Activity log'),
                ]),
                self::group('system', __('System & data migration'), 'server', [
                    'health' => __('System health'),
                    'email-log' => __('Email log'),
                    'migration.runs' => __('Migration runs'),
                    'migration.quarantine' => __('Quarantine queue'),
                    'migration.records' => __('Legacy records'),
                    'migration.redirects' => __('Legacy redirects'),
                    'migration.reconciliation' => __('Reconciliation'),
                ]),
            ],
            'partner' => self::isClassifiedSeller() ? [
                self::link('dashboard', __('Dashboard'), 'layout-dashboard'),
                self::group('listings', __('Listings'), 'layers', [
                    'marketplace.ads' => __('My listings'),
                    'marketplace.ads.create' => __('Post a listing'),
                    'advertising' => __('Ads & boosts'),
                ]),
                self::group('buyers', __('Buyers'), 'message-square', [
                    'marketplace.inquiries' => __('Inquiries'),
                    'marketplace.requests' => __('Viewing & reserve requests'),
                ]),
                self::group('account', __('Shop & account'), 'store', [
                    'store' => __('Profile'),
                    'change-requests' => __('Change requests'),
                    'contracts' => __('Contracts'),
                    'documents' => __('Documents'),
                ]),
                self::link('support', __('Support'), 'life-buoy'),
            ] : [
                self::link('dashboard', __('Dashboard'), 'layout-dashboard'),
                self::group('orders', __('Orders'), 'shopping-bag', [
                    'orders' => __('Orders'),
                    'returns' => __('Returns'),
                    'disputes' => __('Disputes'),
                ]),
                self::group('catalog', __('Catalog'), 'tag', [
                    'products' => __('Products'),
                    'product-requests' => __('Product requests'),
                    'reviews' => __('Reviews'),
                ]),
                self::group('inventory', __('Inventory & FBN'), 'warehouse', [
                    'inventory' => __('Inventory & warehouses'),
                    'packaging' => __('Packaging supplies'),
                ]),
                self::group('marketing', __('Marketing'), 'megaphone', [
                    'coupons' => __('Coupons'),
                    'flash-sales' => __('Flash sales'),
                    'campaigns' => __('Marketer campaigns'),
                    'advertising' => __('Advertising'),
                ]),
                self::group('finance', __('Finance'), 'banknote', [
                    'earnings' => __('Earnings'),
                    'statements' => __('Statements & invoices'),
                    'payouts' => __('Payouts'),
                    'subscription' => __('Subscription'),
                ]),
                self::group('account', __('Store & team'), 'store', [
                    'store' => __('Store settings'),
                    'team' => __('Team & permissions'),
                    'change-requests' => __('Change requests'),
                    'contracts' => __('Contracts'),
                    'documents' => __('Documents'),
                ]),
                self::link('support', __('Support'), 'life-buoy'),
            ],
            'marketer' => array_values(array_filter([
                self::link('dashboard', __('Overview'), 'layout-dashboard'),
                self::group('campaigns', __('Campaigns'), 'megaphone', [
                    'marketplace' => __('Campaign marketplace'),
                    'invitations' => __('Invitations'),
                    'campaigns' => __('Active campaigns'),
                    'links' => __('Links & coupons'),
                ]),
                self::group('promotion', __('Promotion tools'), 'sparkles', [
                    'cross-promotion' => __('Cross-promotion'),
                    'flash-sales' => __('Flash sales'),
                    'advertising' => __('Advertising'),
                ]),
                self::group('products', __('My products'), 'package', [
                    'products' => __('My products'),
                    'product-requests' => __('Product requests'),
                ]),
                self::isBroker() ? self::group('broker', __('Broker'), 'briefcase', [
                    'broker.leads' => __('Leads pipeline'),
                    'broker.requests' => __('Viewing & reserve requests'),
                    'broker.special-requests' => __('Special requests'),
                    'broker.properties' => __('Properties'),
                ]) : null,
                self::group('earnings', __('Earnings'), 'wallet', [
                    'wallet' => __('Wallet & withdrawals'),
                    'reports' => __('Monthly reports'),
                ]),
                self::group('account', __('Profile & contract'), 'user-circle', [
                    'profile' => __('Profile & socials'),
                    'contract' => __('Marketer contract'),
                ]),
                self::link('support', __('Support'), 'life-buoy'),
            ])),
            'travel_agency' => [
                self::link('dashboard', __('Dashboard'), 'layout-dashboard'),
                self::group('trips', __('Trips'), 'plane', [
                    'packages' => __('Packages & trips'),
                    'departures' => __('Departures & capacity'),
                ]),
                self::group('bookings', __('Bookings'), 'ticket', [
                    'bookings' => __('Bookings'),
                    'inquiries' => __('Inquiries'),
                    'reports' => __('Booking reports'),
                ]),
                self::group('promotions', __('Promotions'), 'megaphone', [
                    'promotions' => __('Promotions'),
                    'promotions.create' => __('Promote a trip'),
                ]),
                self::group('account', __('Agency & team'), 'building', [
                    'team' => __('Team & permissions'),
                    'change-requests' => __('Change requests'),
                ]),
                self::link('support', __('Support'), 'life-buoy'),
            ],
            'carrier' => [
                self::link('dashboard', __('Overview'), 'layout-dashboard'),
                self::link('dispatch', __('Live dispatch'), 'route'),
                self::group('shipments', __('Shipments'), 'truck', [
                    'pickups' => __('Pickup requests'),
                    'shipments' => __('Shipments'),
                    'assignments' => __('Assignments'),
                    'exceptions' => __('Failed deliveries'),
                ]),
                self::group('team', __('Drivers'), 'users', [
                    'agents' => __('Drivers & supervisors'),
                    'driver-documents' => __('Driver documents'),
                ]),
                self::group('money', __('Money & claims'), 'banknote', [
                    'cod' => __('COD & reconciliation'),
                    'withdrawals' => __('Agent withdrawals'),
                    'claims' => __('Claims & scorecard'),
                ]),
                self::link('company', __('Company settings'), 'building'),
                self::link('support', __('Support'), 'life-buoy'),
            ],
            'delivery' => [
                self::link('dashboard', __('My day'), 'sun'),
                self::link('assignments', __('Assignments'), 'clipboard-list'),
                self::group('money', __('Money'), 'wallet', [
                    'cod' => __('COD cash & settlements'),
                    'earnings' => __('Earnings'),
                ]),
                self::group('account', __('Profile & documents'), 'user-circle', [
                    'profile' => __('Profile & vehicle'),
                    'documents' => __('Documents'),
                ]),
            ],
            default => [],
        };
    }

    /**
     * @return array{type: string, key: string, label: string, icon: string, items: array<string, string>}
     */
    private static function link(string $routeSuffix, string $label, string $icon): array
    {
        return ['type' => 'link', 'key' => $routeSuffix, 'label' => $label, 'icon' => $icon, 'items' => [$routeSuffix => $label]];
    }

    /**
     * @param  array<string, string>  $items
     * @return array{type: string, key: string, label: string, icon: string, items: array<string, string>}
     */
    private static function group(string $key, string $label, string $icon, array $items): array
    {
        return ['type' => 'group', 'key' => $key, 'label' => $label, 'icon' => $icon, 'items' => $items];
    }

    /**
     * Work waiting on the user: listings to approve (admin) and new orders (Seller Center).
     */
    private static function count(string $panelKey, string $routeSuffix): ?int
    {
        $count = match ("{$panelKey}.{$routeSuffix}") {
            'admin.approvals' => rescue(fn (): int => app(AdminVendorScope::class)->apply(Listing::query())->where('status', ListingStatus::PendingReview)->count(), 0),
            'partner.orders' => rescue(function (): int {
                $workspace = app(VendorWorkspace::class);

                return $workspace->has() ? SubOrder::query()->where('vendor_id', $workspace->vendor()->id)->where('status', SubOrderStatus::Placed)->count() : 0;
            }, 0),
            default => 0,
        };

        return $count > 0 ? $count : null;
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
