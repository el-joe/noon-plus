<?php

namespace App\Domains\Finance\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Enums\SubscriptionPaymentPreference;
use App\Domains\Finance\Enums\VendorSubscriptionStatus;
use App\Domains\Finance\Models\VendorSubscription;
use App\Domains\Finance\Models\VendorSubscriptionInvoice;
use App\Domains\Finance\Models\VendorSubscriptionPlan;
use App\Domains\Finance\Support\SubscriptionInvoiceDocument;
use App\Domains\Finance\Support\VendorSubscriptions;
use App\Domains\Membership\Enums\MembershipInterval;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Vendors\Models\Vendor;
use App\Http\Controllers\Controller;
use App\Shared\Http\LikeSearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin → Finance → Vendor subscriptions (FR-FIN-7, SRS §4.1.12): the
 * country's seller plans (price per interval, trial, listing cap, features),
 * the subscriptions with their invoices and history; finance can subscribe
 * a vendor, change its plan, cancel it at once and deduct an open invoice
 * from the next payout. Plans in use are switched off, never deleted.
 */
class VendorSubscriptionAdminController extends Controller
{
    public function __construct(private CountryContext $countryContext, private VendorSubscriptions $subscriptions) {}

    public function plans(): View
    {
        return view('panels.admin.finance.subscriptions.plans', [
            'plans' => VendorSubscriptionPlan::query()->addSelect(['subscribers_count' => VendorSubscription::query()->selectRaw('count(*)')
                ->whereColumn('vendor_subscription_plan_id', 'vendor_subscription_plans.id')
                ->whereIn('status', [VendorSubscriptionStatus::Trialing, VendorSubscriptionStatus::Active, VendorSubscriptionStatus::PastDue])])
                ->orderBy('sort_order')->orderBy('id')->get(),
            'country' => $this->countryContext->current(),
        ]);
    }

    public function storePlan(Request $request): RedirectResponse
    {
        VendorSubscriptionPlan::query()->create($this->validatedPlan($request, null));

        return back()->with('status', __('Plan created.'));
    }

    public function editPlan(int $plan): View
    {
        return view('panels.admin.finance.subscriptions.plan-form', ['plan' => VendorSubscriptionPlan::query()->findOrFail($plan), 'country' => $this->countryContext->current()]);
    }

    public function updatePlan(Request $request, int $plan): RedirectResponse
    {
        $plan = VendorSubscriptionPlan::query()->findOrFail($plan);
        $plan->update($this->validatedPlan($request, $plan));

        return redirect()->route('admin.finance.subscription-plans')->with('status', __('Plan saved.'));
    }

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::enum(VendorSubscriptionStatus::class)],
            'plan' => ['nullable', 'integer'],
        ]);

        return view('panels.admin.finance.subscriptions.index', [
            'subscriptions' => VendorSubscription::query()->with(['plan', 'vendor:id,store_name'])
                ->when($filters['q'] ?? null, fn ($query, string $term) => $query->whereIn('vendor_id', Vendor::query()->select('id')->where('store_name', 'like', LikeSearch::contains($term))))
                ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
                ->when($filters['plan'] ?? null, fn ($query, int $plan) => $query->where('vendor_subscription_plan_id', $plan))
                ->latest('id')->paginate(25)->withQueryString(),
            'plans' => VendorSubscriptionPlan::query()->orderBy('sort_order')->pluck('name_en', 'id')->all(),
            'activePlans' => VendorSubscriptionPlan::query()->where('is_active', true)->orderBy('sort_order')->pluck('name_en', 'id')->all(),
            'filters' => $filters,
            'country' => $this->countryContext->current(),
        ]);
    }

    public function show(int $subscription): View
    {
        $subscription = VendorSubscription::query()->with(['plan', 'pendingPlan', 'vendor', 'invoices', 'transitions'])->findOrFail($subscription);

        return view('panels.admin.finance.subscriptions.show', [
            'subscription' => $subscription,
            'activePlans' => VendorSubscriptionPlan::query()->where('is_active', true)->orderBy('sort_order')->pluck('name_en', 'id')->all(),
            'country' => $this->countryContext->current(),
        ]);
    }

    public function subscribe(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'vendor_id' => ['required', 'integer'],
            'plan_id' => ['required', 'integer'],
            'payment_preference' => ['required', Rule::enum(SubscriptionPaymentPreference::class)],
        ]);
        $subscription = $this->subscriptions->subscribe(
            Vendor::query()->findOrFail($data['vendor_id']),
            VendorSubscriptionPlan::query()->findOrFail($data['plan_id']),
            SubscriptionPaymentPreference::from($data['payment_preference']),
            ActorType::Admin,
            $request->user()->id,
        );

        return redirect()->route('admin.finance.subscriptions.show', $subscription->id)->with('status', __('Vendor subscribed.'));
    }

    public function changePlan(Request $request, int $subscription): RedirectResponse
    {
        $planId = $request->validate(['plan_id' => ['required', 'integer']])['plan_id'];
        $this->subscriptions->changePlan(VendorSubscription::query()->findOrFail($subscription), VendorSubscriptionPlan::query()->findOrFail($planId));

        return back()->with('status', __('Plan change saved.'));
    }

    public function cancel(Request $request, int $subscription): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'];
        $this->subscriptions->cancel(VendorSubscription::query()->findOrFail($subscription), ActorType::Admin, $request->user()->id, $reason);

        return back()->with('status', __('Subscription cancelled.'));
    }

    public function deduct(Request $request, int $invoice): RedirectResponse
    {
        $this->subscriptions->deductFromPayout(VendorSubscriptionInvoice::query()->findOrFail($invoice), ActorType::Admin, $request->user()->id);

        return back()->with('status', __('Invoice will be deducted from the next payout.'));
    }

    public function invoicePdf(int $invoice, SubscriptionInvoiceDocument $document): Response
    {
        return $document->pdf(VendorSubscriptionInvoice::query()->findOrFail($invoice));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPlan(Request $request, ?VendorSubscriptionPlan $plan): array
    {
        $data = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:32', Rule::unique(VendorSubscriptionPlan::class, 'code')->ignore($plan?->id)],
            'name_en' => ['required', 'string', 'max:120'],
            'name_ar' => ['required', 'string', 'max:120'],
            'interval' => ['required', Rule::enum(MembershipInterval::class)],
            'price' => ['required', 'decimal:0,3', 'min:0', 'max:1000000'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:180'],
            'max_active_listings' => ['nullable', 'integer', 'min:1'],
            'features' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'between:0,999'],
            'is_active' => ['boolean'],
        ]);
        $data['features'] = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($data['features'] ?? '')) ?: [])));

        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data + ['is_active' => false, 'max_active_listings' => null];
    }
}
