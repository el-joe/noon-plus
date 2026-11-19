<?php

namespace App\Domains\Vendors\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Http\Controllers\Admin\AcquisitionCommissionController;
use App\Domains\Finance\Models\SubOrderSettlement;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Identity\Support\AdminVendorScope;
use App\Domains\Listings\Models\Listing;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Vendors\Enums\VendorStatus;
use App\Domains\Vendors\Models\Vendor;
use App\Domains\Vendors\Support\VendorAccountStateMachine;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\Http\LikeSearch;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Users & vendors → Vendors (QC-019, matrix §3.2 `vendors`): the
 * country's sellers, status (suspend / reactivate) and the account manager
 * assignment. Account managers only see their assigned vendors, and with
 * `finance.hidden` the platform economics (commission, net payouts) are
 * masked (matrix §3.3). Changes are audited.
 */
class VendorAdminController extends Controller
{
    /**
     * QC-016: vendor account states (catalogue Appendix A).
     */
    public const STATUSES = ['active', 'on_hold', 'suspended', 'blacklisted'];

    public function __construct(private CountryContext $countryContext, private AdminVendorScope $scope) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:24'],
            'type' => ['nullable', Rule::in(['product', 'classified'])],
            'manager' => ['nullable', 'integer'],
        ]);

        $vendors = $this->scope->apply(Vendor::query(), 'id')
            ->withCount('listings')
            ->when($filters['q'] ?? null, fn ($query, string $search) => $query->where(fn ($inner) => $inner->where('store_name', 'like', LikeSearch::contains($search))->orWhere('slug', 'like', LikeSearch::contains($search))))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['type'] ?? null, fn ($query, string $type) => $query->where('type', $type))
            ->when($filters['manager'] ?? null, fn ($query, int $manager) => $query->where('account_manager_id', $manager))
            ->orderBy('store_name')->paginate(25)->withQueryString();

        return view('panels.admin.vendors.index', [
            'vendors' => $vendors,
            'managers' => $this->accountManagers(),
            'filters' => $filters,
            'country' => $this->countryContext->current(),
            'restricted' => $this->scope->isRestricted(),
        ]);
    }

    public function show(Request $request, int $vendor): View
    {
        $vendor = $this->find($vendor);
        $financeHidden = $this->scope->financeHidden();

        return view('panels.admin.vendors.show', [
            'vendor' => $vendor,
            'owner' => User::query()->find($vendor->owner_user_id, ['id', 'name', 'email']),
            'manager' => $vendor->account_manager_id === null ? null : User::query()->find($vendor->account_manager_id, ['id', 'name', 'email']),
            'managers' => $request->user()->can('vendors.assign_account_manager') ? $this->accountManagers() : collect(),
            'acquirer' => $vendor->acquired_by_user_id === null ? null : User::query()->find($vendor->acquired_by_user_id, ['id', 'name', 'email']),
            'acquirers' => $request->user()->can('vendors.assign_account_manager') ? AcquisitionCommissionController::acquirersFor($this->countryContext->current()->id) : collect(),
            'listingCounts' => Listing::query()->where('vendor_id', $vendor->id)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'recentShipments' => SubOrder::query()->where('vendor_id', $vendor->id)->latest('id')->limit(10)->get(),
            'economics' => $financeHidden ? null : [
                'gross' => (string) SubOrderSettlement::query()->where('vendor_id', $vendor->id)->sum('gross'),
                'commission' => (string) SubOrderSettlement::query()->where('vendor_id', $vendor->id)->sum('commission'),
                'net' => (string) SubOrderSettlement::query()->where('vendor_id', $vendor->id)->sum('net'),
            ],
            'country' => $this->countryContext->current(),
        ]);
    }

    public function status(Request $request, int $vendor, VendorAccountStateMachine $machine): RedirectResponse
    {
        $vendor = $this->find($vendor);
        $data = $request->validate(['status' => ['required', Rule::in(self::STATUSES)], 'reason' => ['required', 'string', 'min:3', 'max:255']]);
        $before = $vendor->status;

        // Matrix §3.2 "vendors": account managers may not blacklist.
        if ($data['status'] === VendorStatus::Blacklisted->value && $this->scope->vendorIds() !== null) {
            abort(403);
        }

        try {
            $machine->move($vendor, VendorStatus::from($data['status']), ActorType::Admin, $request->user()->id, $data['reason']);
        } catch (TransitionRefused $refused) {
            return back()->withErrors(['status' => $refused->getMessage()]);
        }

        $this->audit($request, $vendor, 'status_changed', ['status' => $before], ['status' => $data['status'], 'reason' => $data['reason']]);

        return back()->with('status', __('Seller status updated.'));
    }

    public function accountManager(Request $request, int $vendor): RedirectResponse
    {
        $vendor = $this->find($vendor);
        $data = $request->validate(['account_manager_id' => ['nullable', 'integer', Rule::in($this->accountManagers()->pluck('id')->all())]]);
        $before = $vendor->account_manager_id;
        $vendor->forceFill(['account_manager_id' => $data['account_manager_id'] ?? null])->save();

        // FR-FIN-6: the first account manager is credited with the acquisition unless someone already is.
        if ($vendor->account_manager_id !== null && $vendor->acquired_by_user_id === null) {
            $vendor->forceFill(['acquired_by_user_id' => $vendor->account_manager_id, 'acquired_at' => $vendor->acquired_at ?? $vendor->created_at ?? now()])->save();
        }

        $this->audit($request, $vendor, 'account_manager_assigned', ['account_manager_id' => $before], ['account_manager_id' => $vendor->account_manager_id]);

        return back()->with('status', __('Account manager saved.'));
    }

    /**
     * FR-FIN-6: who brought the vendor in (earns the acquisition commission).
     */
    public function acquirer(Request $request, int $vendor): RedirectResponse
    {
        $vendor = $this->find($vendor);
        $data = $request->validate([
            'acquired_by_user_id' => ['nullable', 'integer', Rule::in(AcquisitionCommissionController::acquirersFor($this->countryContext->current()->id)->pluck('id')->all())],
            'acquired_at' => ['nullable', 'date'],
        ]);
        $before = ['acquired_by_user_id' => $vendor->acquired_by_user_id, 'acquired_at' => $vendor->acquired_at];
        $vendor->forceFill([
            'acquired_by_user_id' => $data['acquired_by_user_id'] ?? null,
            'acquired_at' => ($data['acquired_by_user_id'] ?? null) === null ? null : ($data['acquired_at'] ?? $vendor->created_at ?? now()),
        ])->save();
        $this->audit($request, $vendor, 'acquirer_assigned', $before, ['acquired_by_user_id' => $vendor->acquired_by_user_id, 'acquired_at' => (string) $vendor->acquired_at]);

        return back()->with('status', __('Acquisition saved.'));
    }

    /**
     * Admins of this country holding the account-manager role here.
     *
     * @return Collection<int, User>
     */
    private function accountManagers(): Collection
    {
        $country = $this->countryContext->current();

        return User::query()
            ->whereHas('memberships', fn ($membership) => $membership->where('role', MembershipRole::Admin)->where('status', 'active')->where('country_id', $country->id))
            ->whereHas('roles', fn ($roles) => $roles->where('name', 'account_manager'))
            ->orderBy('name')->get(['id', 'name', 'email']);
    }

    private function find(int $vendor): Vendor
    {
        return $this->scope->apply(Vendor::query(), 'id')->findOrFail($vendor);
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function audit(Request $request, Vendor $vendor, string $event, array $old, array $new): void
    {
        activity('vendors')->performedOn($vendor)->causedBy($request->user())->event($event)
            ->withProperties(['country' => $this->countryContext->current()->code, 'old' => $old, 'attributes' => $new])
            ->log("Vendor {$vendor->store_name}: ".str_replace('_', ' ', $event));
    }
}
