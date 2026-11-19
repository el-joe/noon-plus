<?php

namespace App\Domains\Marketers\Http\Controllers\Admin;

use App\Domains\Campaigns\Models\CampaignFeeRule;
use App\Domains\Catalog\Models\Category;
use App\Domains\Contracts\Support\ContractResolver;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Marketers\Enums\MarketerStatus;
use App\Domains\Marketers\Enums\WithdrawalStatus;
use App\Domains\Marketers\Models\CrossPromoPolicy;
use App\Domains\Marketers\Models\MarketerCommissionRule;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Marketers\Models\MarketerWithdrawal;
use App\Domains\Marketers\Support\CommissionRules;
use App\Domains\Marketers\Support\CrossPromotion;
use App\Domains\Marketers\Support\MarketerProfiles;
use App\Domains\Marketers\Support\MarketingSettings;
use App\Domains\Marketers\Support\Withdrawals;
use App\Http\Controllers\Controller;
use App\Shared\Http\LikeSearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Marketers & campaigns (SRS §4.1.6, matrix §3.2 `marketers`,
 * `marketer_settings`, `cross_promotion`): marketer approval / suspension,
 * the Marketer Settings grid (Figma 97:1720: default commission rules per
 * category, Marketing Fees, general keys), marketer withdrawals and the
 * cross-promotion policies with their participation.
 */
class MarketerAdminController extends Controller
{
    public function __construct(private CountryContext $countryContext) {}

    public function index(Request $request): View
    {
        return view('panels.admin.marketers.index', [
            'marketers' => MarketerProfile::query()
                ->when($request->query('status'), fn ($query, $status) => $query->where('global_status', $status))
                ->when($request->query('q'), fn ($query, $q) => $query->where('display_name', 'like', LikeSearch::contains($q)))
                ->latest('id')->paginate(25)->withQueryString(),
            'statuses' => MarketerStatus::cases(),
        ]);
    }

    public function status(Request $request, int $marketer, MarketerProfiles $profiles): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::enum(MarketerStatus::class)], 'reason' => ['nullable', 'string', 'max:255']]);
        $profiles->transition(MarketerProfile::query()->findOrFail($marketer), MarketerStatus::from($data['status']), $request->user()->id, $data['reason'] ?? null);

        return back()->with('status', __('Marketer updated.'));
    }

    public function settings(MarketingSettings $settings, ContractResolver $resolver): View
    {
        $rules = MarketerCommissionRule::query()->whereNull('marketer_profile_id')->where('vertical', 'product')->get()->keyBy(fn ($rule) => $rule->category_id ?? 0);

        return view('panels.admin.marketers.settings', [
            'categories' => Category::query()->whereNull('parent_id')->orderBy('sort_order')->orderBy('name_en')->get(),
            'rules' => $rules,
            'fixedFee' => CampaignFeeRule::query()->where('kind', CampaignFeeRule::KIND_FIXED)->first(),
            'variableFees' => CampaignFeeRule::query()->where('kind', CampaignFeeRule::KIND_VARIABLE)->get(),
            'general' => collect(MarketingSettings::KEYS)->keys()->reject(fn (string $key): bool => $key === 'allowed_fulfilment_models')->mapWithKeys(fn (string $key): array => [$key => $settings->get($key)]),
            'uncovered' => $resolver->uncoveredMarketerDestinations($this->countryContext->current()),
            'country' => $this->countryContext->current(),
        ]);
    }

    public function saveRule(Request $request, CommissionRules $rules): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'integer'],
            'marketer_profile_id' => ['nullable', 'integer'],
            'influencer_fixed' => ['nullable', 'numeric', 'min:0'],
            'affiliate_percent' => ['nullable', 'numeric', 'between:0,100'],
            'broker_fixed' => ['nullable', 'numeric', 'min:0'],
            'broker_percent' => ['nullable', 'numeric', 'between:0,100'],
            'fee_percent' => ['nullable', 'numeric', 'between:0,100'],
            'fee_fixed' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (isset($data['marketer_profile_id'])) {
            MarketerProfile::query()->findOrFail($data['marketer_profile_id']);
        }

        if (isset($data['category_id'])) {
            Category::query()->findOrFail($data['category_id']);
        }

        $rules->save($data['marketer_profile_id'] ?? null, $data['category_id'] ?? null, array_map(fn ($value) => $value === null ? null : (string) $value, array_intersect_key($data, array_flip(MarketerCommissionRule::FIELDS))));

        return back()->with('status', __('Commission rule saved.'));
    }

    public function saveFee(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in([CampaignFeeRule::KIND_FIXED, CampaignFeeRule::KIND_VARIABLE])],
            'category_id' => ['nullable', 'integer'],
            'fulfilment' => ['nullable', Rule::in(['fbm', 'fbn', 'cross_dock'])],
            'amount' => ['required', 'numeric', 'min:0'],
            'percent' => ['required', 'numeric', 'between:0,100'],
        ]);
        $match = $data['kind'] === CampaignFeeRule::KIND_FIXED
            ? ['kind' => CampaignFeeRule::KIND_FIXED]
            : ['kind' => CampaignFeeRule::KIND_VARIABLE, 'category_id' => $data['category_id'] ?? null, 'fulfilment' => $data['fulfilment'] ?? null];

        $rule = CampaignFeeRule::query()->where($match)->first() ?? new CampaignFeeRule($match);
        $rule->fill(['amount' => (string) $data['amount'], 'percent' => (string) $data['percent']])->save();

        return back()->with('status', __('Marketing fee saved.'));
    }

    public function saveGeneral(Request $request, MarketingSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'invitation_timeout_hours' => ['required', 'integer', 'between:1,168'],
            'auto_approve_hours' => ['required', 'integer', 'between:1,720'],
            'attribution_window_days' => ['required', 'integer', 'between:1,90'],
            'referral_grace_days' => ['required', 'integer', 'between:0,30'],
            'clearing_days' => ['required', 'integer', 'between:0,90'],
            'min_stock_for_campaign' => ['required', 'integer', 'min:0'],
            'attribution_model' => ['required', Rule::in(['last_click', 'first_click', 'linear'])],
            'campaign_fee_refund_policy' => ['required', Rule::in(['none', 'before_first_conversion', 'pro_rata'])],
            'cross_promotion_enabled' => ['boolean'],
        ]);

        foreach ($data as $key => $value) {
            $settings->set($key, $value, $request->user()->id);
        }

        return back()->with('status', __('Marketer settings saved for :country.', ['country' => $this->countryContext->current()->name_en]));
    }

    public function withdrawals(Request $request): View
    {
        return view('panels.admin.marketers.withdrawals', [
            'withdrawals' => MarketerWithdrawal::query()->with('marketer')->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))->latest('id')->paginate(25),
        ]);
    }

    public function withdrawal(Request $request, int $withdrawal, Withdrawals $withdrawals): RedirectResponse
    {
        $withdrawal = MarketerWithdrawal::query()->findOrFail($withdrawal);
        $userId = $request->user()->id;

        match ($request->input('action')) {
            'approve' => $withdrawals->approve($withdrawal, $userId),
            'process' => $withdrawals->process($withdrawal, $userId),
            default => $withdrawals->reject($withdrawal, $userId, (string) ($request->input('reason') ?: 'Rejected')),
        };

        return back()->with('status', __('Withdrawal :status.', ['status' => $withdrawal->refresh()->status->value]));
    }

    public function crossPromotion(CrossPromotion $crossPromotion): View
    {
        return view('panels.admin.marketers.cross-promotion', [
            'enabled' => $crossPromotion->enabled(),
            'policies' => CrossPromoPolicy::query()->withCount([
                'participations as met_count' => fn ($query) => $query->where('status', 'met'),
                'participations as missed_count' => fn ($query) => $query->where('status', 'missed'),
            ])->latest('id')->get(),
        ]);
    }

    public function storePolicy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(['peer_promotion', 'obligation'])],
            'activity' => ['required', Rule::in(CrossPromotion::ACTIVITIES)],
            'count' => ['required', 'integer', 'between:1,100'],
            'job_types' => ['array'],
            'job_types.*' => [Rule::in(['influencer', 'affiliate', 'broker'])],
            'min_account_age_days' => ['nullable', 'integer', 'min:0'],
            'min_profile_completion' => ['nullable', 'integer', 'between:0,100'],
            'verified_only' => ['boolean'],
            'min_products' => ['nullable', 'integer', 'min:0'],
            'promoter_percent' => ['nullable', 'numeric', 'between:0,100'],
            'penalty' => ['required', Rule::in(['warning', 'suspend'])],
            'exemptions' => ['nullable', 'string'],
        ]);

        CrossPromoPolicy::query()->create([
            'name' => $data['name'],
            'type' => $data['type'],
            'is_active' => true,
            'eligibility' => array_filter([
                'job_types' => $data['job_types'] ?? [],
                'min_account_age_days' => $data['min_account_age_days'] ?? null,
                'min_profile_completion' => $data['min_profile_completion'] ?? null,
                'verified_only' => (bool) ($data['verified_only'] ?? false),
                'min_products' => $data['min_products'] ?? null,
            ], fn ($value) => $value !== null && $value !== [] && $value !== false),
            'obligation' => ['activity' => $data['activity'], 'count' => (int) $data['count'], 'period' => 'month'],
            'allocation' => isset($data['promoter_percent']) ? ['promoter_percent' => (string) $data['promoter_percent']] : null,
            'penalty' => ['action' => $data['penalty']],
            'exemptions' => array_values(array_filter(array_map('intval', explode(',', (string) ($data['exemptions'] ?? ''))))),
        ]);

        return back()->with('status', __('Policy created.'));
    }

    public function togglePolicy(int $policy): RedirectResponse
    {
        $policy = CrossPromoPolicy::query()->findOrFail($policy);
        $policy->forceFill(['is_active' => ! $policy->is_active])->save();

        return back()->with('status', $policy->is_active ? __('Policy active.') : __('Policy paused.'));
    }

    /**
     * Statuses shown in the withdrawal filter.
     *
     * @return array<int, WithdrawalStatus>
     */
    public static function withdrawalStatuses(): array
    {
        return WithdrawalStatus::cases();
    }
}
