<?php

namespace App\Domains\Membership\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Membership\Enums\MembershipInterval;
use App\Domains\Membership\Enums\MembershipStatus;
use App\Domains\Membership\Models\Membership;
use App\Domains\Membership\Models\MembershipPlan;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\Http\LikeSearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Finance → Nawy One (QA-011, settings catalogue §9): the
 * country's membership plans (price per interval, trial days, free
 * delivery) and its subscriptions. A plan in use is switched off rather
 * than deleted; price changes apply from the next renewal. Audited.
 */
class MembershipAdminController extends Controller
{
    public function __construct(private CountryContext $countryContext) {}

    public function plans(): View
    {
        return view('panels.admin.membership.plans', [
            'plans' => MembershipPlan::query()->addSelect(['members_count' => Membership::query()->selectRaw('count(*)')
                ->whereColumn('membership_plan_id', 'membership_plans.id')
                ->whereIn('status', [MembershipStatus::Active, MembershipStatus::Trialing, MembershipStatus::PastDue])])
                ->orderBy('interval')->orderBy('id')->get(),
            'country' => $this->countryContext->current(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        MembershipPlan::query()->create($this->validated($request, null));

        return back()->with('status', __('Plan created.'));
    }

    public function edit(int $plan): View
    {
        return view('panels.admin.membership.plan-form', ['plan' => MembershipPlan::query()->findOrFail($plan), 'country' => $this->countryContext->current()]);
    }

    public function update(Request $request, int $plan): RedirectResponse
    {
        $plan = MembershipPlan::query()->findOrFail($plan);
        $plan->update($this->validated($request, $plan));

        return redirect()->route('admin.membership')->with('status', __('Plan saved.'));
    }

    public function subscriptions(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::enum(MembershipStatus::class)],
            'plan' => ['nullable', 'integer'],
        ]);
        $userIds = filled($filters['q'] ?? null)
            ? User::query()->where('email', 'like', LikeSearch::contains($filters['q']))->orWhere('name', 'like', LikeSearch::contains($filters['q']))->limit(200)->pluck('id')
            : null;
        $memberships = Membership::query()->with('plan')
            ->when($userIds !== null, fn ($query) => $query->whereIn('customer_user_id', $userIds))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['plan'] ?? null, fn ($query, int $plan) => $query->where('membership_plan_id', $plan))
            ->latest('id')->paginate(25)->withQueryString();

        return view('panels.admin.membership.subscriptions', [
            'memberships' => $memberships,
            'customers' => User::query()->whereIn('id', $memberships->pluck('customer_user_id'))->get(['id', 'name', 'email'])->keyBy('id'),
            'plans' => MembershipPlan::query()->pluck('name_en', 'id')->all(),
            'filters' => $filters,
            'country' => $this->countryContext->current(),
        ]);
    }

    public function subscription(int $membership): View
    {
        $membership = Membership::query()->with(['plan', 'invoices', 'transitions'])->findOrFail($membership);

        return view('panels.admin.membership.subscription', [
            'membership' => $membership,
            'customer' => User::query()->find($membership->customer_user_id, ['id', 'name', 'email']),
            'country' => $this->countryContext->current(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?MembershipPlan $plan): array
    {
        $data = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:32', Rule::unique(MembershipPlan::class, 'code')->ignore($plan?->id)],
            'name_en' => ['required', 'string', 'max:120'],
            'name_ar' => ['required', 'string', 'max:120'],
            'interval' => ['required', Rule::enum(MembershipInterval::class)],
            'price' => ['required', 'decimal:0,3', 'gt:0', 'max:100000'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:90'],
            'free_shipping' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        return $data + ['free_shipping' => false, 'is_active' => false];
    }
}
