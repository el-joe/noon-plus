<?php

namespace App\Domains\Panels\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Models\Carrier;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Marketers\Support\MarketerWorkspace;
use App\Domains\Vendors\Models\Vendor;
use App\Domains\Vendors\Support\VendorWorkspace;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Staff permissions inside a tenant (QC-020, role-permission matrix §4
 * T1–T3 and §5–§8). The tenant owner — store or travel agency owner,
 * owner supervisor of a shipping company, a marketer in their own Creator
 * Studio — has every right (T3). Other members get what their staff role
 * (`role_memberships.tenant_role`) grants per permission group: `F` full
 * (read and change), `V` view only. A member without a role gets the
 * panel's most restricted role. Reads (GET/HEAD) need V, changes need F.
 */
class TenantPermissions
{
    /**
     * Staff roles per panel → permission group → level.
     *
     * @var array<string, array<string, array<string, string>>>
     */
    public const MATRIX = [
        'partner' => [
            'manager' => ['dashboard' => 'F', 'orders' => 'F', 'returns' => 'F', 'listings' => 'F', 'reviews' => 'F', 'campaigns' => 'F', 'marketing' => 'F', 'legal' => 'V', 'classifieds' => 'F', 'finance' => 'V', 'profile' => 'F', 'documents' => 'F'],
            'catalog' => ['dashboard' => 'V', 'listings' => 'F', 'reviews' => 'F', 'legal' => 'V', 'classifieds' => 'F'],
            'orders' => ['dashboard' => 'V', 'orders' => 'F', 'returns' => 'F', 'listings' => 'V', 'reviews' => 'V', 'legal' => 'V', 'profile' => 'V'],
            'finance' => ['dashboard' => 'V', 'orders' => 'V', 'returns' => 'V', 'campaigns' => 'V', 'marketing' => 'V', 'legal' => 'V', 'finance' => 'F', 'profile' => 'V'],
            'marketing' => ['dashboard' => 'V', 'listings' => 'V', 'reviews' => 'V', 'campaigns' => 'F', 'marketing' => 'F', 'legal' => 'V', 'classifieds' => 'V'],
            'viewer' => ['dashboard' => 'V', 'orders' => 'V', 'returns' => 'V', 'listings' => 'V', 'reviews' => 'V', 'campaigns' => 'V', 'marketing' => 'V', 'legal' => 'V', 'classifieds' => 'V', 'profile' => 'V', 'documents' => 'V'],
        ],
        'travel_agency' => [
            'operations' => ['dashboard' => 'V', 'packages' => 'F', 'bookings' => 'F', 'inquiries' => 'F', 'reports' => 'V'],
            'finance' => ['dashboard' => 'V', 'bookings' => 'V', 'reports' => 'V'],
            'viewer' => ['dashboard' => 'V', 'packages' => 'V', 'bookings' => 'V', 'inquiries' => 'V', 'reports' => 'V'],
        ],
        'carrier' => [
            'supervisor' => ['dashboard' => 'V', 'agents' => 'F', 'dispatch' => 'F', 'cod' => 'V', 'claims' => 'V'],
        ],
        'marketer' => [
            'agency' => ['studio' => 'F', 'campaigns' => 'F', 'wallet' => 'V', 'contract' => 'V', 'profile' => 'F', 'broker' => 'F'],
        ],
    ];

    /**
     * The role a member without `tenant_role` gets.
     *
     * @var array<string, string>
     */
    public const DEFAULT_ROLE = ['partner' => 'viewer', 'travel_agency' => 'viewer', 'carrier' => 'supervisor', 'marketer' => 'agency'];

    public function __construct(private CountryContext $countryContext, private VendorWorkspace $vendors, private MarketerWorkspace $marketers) {}

    /**
     * @return array<int, string>
     */
    public static function roles(string $panel): array
    {
        return array_keys(self::MATRIX[$panel] ?? []);
    }

    public static function roleLabel(string $role): string
    {
        return match ($role) {
            'manager' => __('Manager'),
            'catalog' => __('Catalog'),
            'orders' => __('Orders & fulfilment'),
            'finance' => __('Finance'),
            'marketing' => __('Marketing'),
            'operations' => __('Operations'),
            'supervisor' => __('Supervisor'),
            'agency' => __('Agency (on behalf)'),
            default => __('Viewer'),
        };
    }

    /**
     * Whether the signed-in user may read (or change) a permission group of
     * the tenant bound to this request. No tenant bound → true (the panel's
     * own workspace middleware refuses).
     */
    public function allows(Request $request, string $panel, string $group, bool $write): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        $context = $this->context($request, $user, $panel);

        if ($context === null || $context['owner']) {
            return true;
        }

        $level = self::MATRIX[$panel][$context['role']][$group] ?? null;

        return $level === 'F' || ($level === 'V' && ! $write);
    }

    /**
     * @return array{owner: bool, role: string}|null
     */
    public function context(Request $request, User $user, string $panel): ?array
    {
        $workspace = $request->attributes->get('ops_workspace');

        $tenant = match ($panel) {
            'partner' => $this->vendors->has() ? $this->vendors->vendor() : ($workspace instanceof Vendor ? $workspace : null),
            'travel_agency' => $request->attributes->get('agency') ?? ($workspace instanceof Vendor ? $workspace : null),
            'carrier' => $request->attributes->get('carrier') ?? ($workspace instanceof Carrier ? $workspace : null),
            'marketer' => $this->marketers->has() ? $this->marketers->marketer() : ($workspace instanceof MarketerProfile ? $workspace : null),
            default => null,
        };

        if ($tenant === null) {
            return null;
        }

        if ($tenant instanceof MarketerProfile) {
            return ['owner' => $tenant->user_id === $user->id, 'role' => self::DEFAULT_ROLE['marketer']];
        }

        if ($tenant->owner_user_id === $user->id) {
            return ['owner' => true, 'role' => 'owner'];
        }

        $roles = match ($panel) {
            'partner' => [MembershipRole::ProductVendor, MembershipRole::ClassifiedSeller],
            'travel_agency' => [MembershipRole::TravelAgency],
            default => [MembershipRole::CarrierSupervisor],
        };
        $tenantRole = $user->memberships()->whereIn('role', $roles)->where('tenant_id', $tenant->id)
            ->where('tenant_type', $tenant instanceof Carrier ? Carrier::TENANT : PanelCountries::VENDOR_TENANT)
            ->when($this->countryContext->has(), fn ($query) => $query->where('country_id', $this->countryContext->current()->id))
            ->value('tenant_role');

        return ['owner' => false, 'role' => in_array($tenantRole, self::roles($panel), true) ? $tenantRole : self::DEFAULT_ROLE[$panel]];
    }
}
