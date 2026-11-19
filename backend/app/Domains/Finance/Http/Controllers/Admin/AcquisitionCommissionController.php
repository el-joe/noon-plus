<?php

namespace App\Domains\Finance\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Enums\AcquisitionCommissionStatus;
use App\Domains\Finance\Models\AcquisitionCommission;
use App\Domains\Finance\Models\AcquisitionCommissionRule;
use App\Domains\Finance\Support\AcquisitionCommissions;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Onboarding\Support\AcquirerReferrals;
use App\Domains\Vendors\Models\Vendor;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Finance → Acquisition commissions (FR-FIN-6, SRS §4.1.5): the
 * country's commission rules (rate inside a monthly sales band, date range,
 * optional acquirer), the monthly commissions (approve → pay by bank
 * transfer, or reject) and "My acquisition commissions" for the signed-in
 * admin or account manager.
 */
class AcquisitionCommissionController extends Controller
{
    public function __construct(private CountryContext $countryContext, private AcquisitionCommissions $commissions) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(AcquisitionCommissionStatus::class)],
            'acquirer' => ['nullable', 'integer'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);
        $rows = $this->filtered($filters)->with('vendor:id,store_name')->latest('period_start')->latest('id')->paginate(25)->withQueryString();

        return view('panels.admin.finance.acquisition.index', [
            'commissions' => $rows,
            'totals' => $this->filtered($filters)->selectRaw('status, SUM(amount) as total, COUNT(*) as rows_count')->groupBy('status')->get()->keyBy(fn ($row) => $row->status->value),
            'acquirers' => $this->acquirers(),
            'users' => User::query()->whereIn('id', $rows->pluck('acquirer_user_id'))->pluck('name', 'id'),
            'filters' => $filters,
            'country' => $this->countryContext->current(),
        ]);
    }

    public function show(int $commission): View
    {
        $commission = AcquisitionCommission::query()->with(['vendor:id,store_name', 'rule', 'transitions'])->findOrFail($commission);

        return view('panels.admin.finance.acquisition.show', [
            'commission' => $commission,
            'acquirer' => User::query()->find($commission->acquirer_user_id, ['id', 'name', 'email']),
            'country' => $this->countryContext->current(),
        ]);
    }

    public function mine(Request $request): View
    {
        $userId = $request->user()->id;
        $base = fn () => AcquisitionCommission::query()->where('acquirer_user_id', $userId);

        return view('panels.admin.finance.acquisition.mine', [
            'commissions' => $base()->with('vendor:id,store_name')->latest('period_start')->latest('id')->paginate(25),
            'totals' => $base()->selectRaw('status, SUM(amount) as total')->groupBy('status')->pluck('total', 'status'),
            'vendorsCount' => Vendor::query()->where('acquired_by_user_id', $userId)->count(),
            'country' => $this->countryContext->current(),
            // FR-FIN-6: the account manager's own join link; applications opened from it name them as acquirer.
            'referralLink' => app(AcquirerReferrals::class)->link($request->user()),
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $month = $request->validate(['month' => ['required', 'date_format:Y-m']])['month'];
        $created = $this->commissions->generate(CarbonImmutable::createFromFormat('Y-m-d H:i', $month.'-15 12:00', $this->countryContext->current()->timezone));

        return back()->with('status', trans_choice('{0} No new commission for this month.|{1} :count commission created.|[2,*] :count commissions created.', $created, ['count' => $created]));
    }

    public function approve(Request $request, int $commission): RedirectResponse
    {
        $this->commissions->approve(AcquisitionCommission::query()->findOrFail($commission), $request->user()->id);

        return back()->with('status', __('Commission approved.'));
    }

    public function pay(Request $request, int $commission): RedirectResponse
    {
        $reference = $request->validate(['bank_reference' => ['required', 'string', 'max:100']])['bank_reference'];
        $this->commissions->pay(AcquisitionCommission::query()->findOrFail($commission), $request->user()->id, $reference);

        return back()->with('status', __('Commission paid.'));
    }

    public function reject(Request $request, int $commission): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'];
        $this->commissions->reject(AcquisitionCommission::query()->findOrFail($commission), $request->user()->id, $reason);

        return back()->with('status', __('Commission rejected.'));
    }

    public function rules(): View
    {
        $rules = AcquisitionCommissionRule::query()->orderByDesc('is_active')->orderBy('min_monthly_sales')->get();

        return view('panels.admin.finance.acquisition.rules', [
            'rules' => $rules,
            'acquirers' => $this->acquirers(),
            'country' => $this->countryContext->current(),
        ]);
    }

    public function storeRule(Request $request): RedirectResponse
    {
        AcquisitionCommissionRule::query()->create($this->validatedRule($request) + ['created_by' => $request->user()->id]);

        return back()->with('status', __('Rule created.'));
    }

    public function editRule(int $rule): View
    {
        return view('panels.admin.finance.acquisition.rule-form', [
            'rule' => AcquisitionCommissionRule::query()->findOrFail($rule),
            'acquirers' => $this->acquirers(),
            'country' => $this->countryContext->current(),
        ]);
    }

    public function updateRule(Request $request, int $rule): RedirectResponse
    {
        AcquisitionCommissionRule::query()->findOrFail($rule)->update($this->validatedRule($request));

        return redirect()->route('admin.finance.acquisition-rules')->with('status', __('Rule saved.'));
    }

    /**
     * Admins of this country (and super admins) who can be credited with
     * bringing a vendor in.
     *
     * @return Collection<int, User>
     */
    public static function acquirersFor(int $countryId): Collection
    {
        return User::query()
            ->whereHas('memberships', fn ($membership) => $membership->where('role', MembershipRole::Admin)->where('status', 'active')
                ->where(fn ($query) => $query->where('country_id', $countryId)->orWhereNull('country_id')))
            ->orderBy('name')->get(['id', 'name', 'email']);
    }

    /**
     * @return Collection<int, User>
     */
    private function acquirers(): Collection
    {
        return self::acquirersFor($this->countryContext->current()->id);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filtered(array $filters): Builder
    {
        return AcquisitionCommission::query()
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['acquirer'] ?? null, fn ($query, int $acquirer) => $query->where('acquirer_user_id', $acquirer))
            ->when($filters['month'] ?? null, fn ($query, string $month) => $query->whereDate('period_start', $month.'-01'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedRule(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'acquirer_user_id' => ['nullable', 'integer', Rule::in($this->acquirers()->pluck('id')->all())],
            'min_monthly_sales' => ['required', 'decimal:0,3', 'min:0'],
            'max_monthly_sales' => ['nullable', 'decimal:0,3', 'gt:min_monthly_sales'],
            'rate_percent' => ['required', 'decimal:0,4', 'gt:0', 'max:100'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'is_active' => ['boolean'],
        ]);

        return $data + ['is_active' => false, 'acquirer_user_id' => null, 'max_monthly_sales' => null, 'ends_on' => null];
    }
}
