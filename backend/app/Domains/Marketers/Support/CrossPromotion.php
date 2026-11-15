<?php

namespace App\Domains\Marketers\Support;

use App\Domains\Campaigns\Enums\CampaignOrigin;
use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Campaigns\Enums\ContentStatus;
use App\Domains\Campaigns\Enums\InvitationStatus;
use App\Domains\Campaigns\Models\CampaignInvitation;
use App\Domains\Campaigns\Models\ContentSubmission;
use App\Domains\Campaigns\Models\MarketerCampaign;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Listings\Models\Listing;
use App\Domains\Marketers\Enums\MarketerStatus;
use App\Domains\Marketers\Models\CrossPromoParticipation;
use App\Domains\Marketers\Models\CrossPromoPolicy;
use App\Domains\Marketers\Models\MarketerProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Cross-promotion policy engine (FR-MKT-15, MP §8): admin policies per
 * country with eligibility (job types, account age, profile completion,
 * verification, categories, minimum own products), an obligation
 * (activity × count per month: peer_promotion, content_submission,
 * accepted_campaign), commission allocation, penalties (warning | suspend)
 * and exemptions. Monthly evaluation records each eligible marketer's
 * participation (met / missed / exempt) once per period and applies the
 * penalty to misses; the dashboard shows the live progress of the
 * current month. Off unless `cross_promotion.enabled`.
 */
class CrossPromotion
{
    public const ACTIVITIES = ['peer_promotion', 'content_submission', 'accepted_campaign'];

    public function __construct(private CountryContext $countryContext, private MarketingSettings $settings, private MarketerProfiles $profiles) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('cross_promotion_enabled');
    }

    public function eligible(CrossPromoPolicy $policy, MarketerProfile $marketer, ?CarbonImmutable $at = null): bool
    {
        $rules = $policy->eligibility ?? [];
        $at ??= CarbonImmutable::now();

        if (! $marketer->isActive()) {
            return false;
        }

        if (! empty($rules['job_types']) && array_intersect($rules['job_types'], $marketer->job_types ?? []) === []) {
            return false;
        }

        if (isset($rules['min_account_age_days']) && $marketer->created_at->diffInDays($at) < (int) $rules['min_account_age_days']) {
            return false;
        }

        if (isset($rules['min_profile_completion']) && $marketer->profileCompletion() < (int) $rules['min_profile_completion']) {
            return false;
        }

        if (! empty($rules['verified_only']) && ! $marketer->is_verified) {
            return false;
        }

        if (! empty($rules['category_ids']) && array_intersect($rules['category_ids'], $marketer->category_ids ?? []) === []) {
            return false;
        }

        if (isset($rules['min_products'])) {
            $products = $marketer->vendor_id === null ? 0 : Listing::query()->where('vendor_id', $marketer->vendor_id)->count();

            if ($products < (int) $rules['min_products']) {
                return false;
            }
        }

        return true;
    }

    public function isExempt(CrossPromoPolicy $policy, MarketerProfile $marketer): bool
    {
        return in_array($marketer->id, array_map('intval', $policy->exemptions ?? []), true);
    }

    /**
     * Completed activities of the obligation in a month (`YYYY-MM`, country time).
     */
    public function completed(CrossPromoPolicy $policy, MarketerProfile $marketer, string $period): int
    {
        $timezone = $this->countryContext->current()->timezone;
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $period.'-01 00:00:00', $timezone)->utc();
        $end = $start->setTimezone($timezone)->addMonthNoOverflow()->utc();

        return match ($policy->obligation['activity'] ?? 'peer_promotion') {
            'content_submission' => ContentSubmission::query()->where('marketer_profile_id', $marketer->id)->where('status', ContentStatus::Approved)->whereBetween('created_at', [$start, $end])->count(),
            'accepted_campaign' => CampaignInvitation::query()->where('marketer_profile_id', $marketer->id)->where('status', InvitationStatus::Accepted)->whereBetween('accepted_at', [$start, $end])->count(),
            default => MarketerCampaign::query()->where('origin', CampaignOrigin::Peer)->where('requested_by_marketer_id', $marketer->id)
                ->whereNotIn('status', [CampaignStatus::Rejected, CampaignStatus::Cancelled])->whereBetween('created_at', [$start, $end])->count(),
        };
    }

    /**
     * Monthly obligation evaluation (idempotent per policy × marketer × period).
     */
    public function evaluate(string $period, ?int $adminUserId = null): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $evaluated = 0;
        $periodEnd = CarbonImmutable::createFromFormat('Y-m-d', $period.'-01', $this->countryContext->current()->timezone)->endOfMonth();

        foreach ($this->activePolicies($periodEnd) as $policy) {
            $required = (int) ($policy->obligation['count'] ?? 1);

            foreach (MarketerProfile::query()->where('global_status', MarketerStatus::Active)->orderBy('id')->get() as $marketer) {
                if (CrossPromoParticipation::query()->where(['policy_id' => $policy->id, 'marketer_profile_id' => $marketer->id, 'period' => $period])->whereNotNull('evaluated_at')->exists()) {
                    continue;
                }

                $exempt = $this->isExempt($policy, $marketer);

                if (! $exempt && ! $this->eligible($policy, $marketer, $periodEnd)) {
                    continue;
                }

                $completed = $this->completed($policy, $marketer, $period);
                $status = $exempt ? 'exempt' : ($completed >= $required ? 'met' : 'missed');
                $penalty = $status === 'missed' ? ($policy->penalty ?? ['action' => 'warning']) : null;

                CrossPromoParticipation::query()->updateOrCreate(
                    ['policy_id' => $policy->id, 'marketer_profile_id' => $marketer->id, 'period' => $period],
                    ['required' => $required, 'completed' => $completed, 'status' => $status, 'penalty' => $penalty, 'evaluated_at' => now()],
                );

                if ($status === 'missed' && ($penalty['action'] ?? null) === 'suspend' && $adminUserId !== null) {
                    $this->profiles->transition($marketer, MarketerStatus::Suspended, $adminUserId, __('Missed the ":policy" obligation for :period', ['policy' => $policy->name, 'period' => $period]));
                }

                $evaluated++;
            }
        }

        return $evaluated;
    }

    /**
     * Participation dashboard rows for one marketer (current month live).
     *
     * @return Collection<int, array{policy: CrossPromoPolicy, eligible: bool, exempt: bool, required: int, completed: int, status: string, history: Collection<int, CrossPromoParticipation>}>
     */
    public function dashboard(MarketerProfile $marketer): Collection
    {
        $now = CarbonImmutable::now($this->countryContext->current()->timezone);
        $period = $now->format('Y-m');

        return $this->activePolicies($now)->map(function (CrossPromoPolicy $policy) use ($marketer, $period): array {
            $required = (int) ($policy->obligation['count'] ?? 1);
            $completed = $this->completed($policy, $marketer, $period);
            $exempt = $this->isExempt($policy, $marketer);

            return [
                'policy' => $policy,
                'eligible' => $this->eligible($policy, $marketer),
                'exempt' => $exempt,
                'required' => $required,
                'completed' => $completed,
                'status' => $exempt ? 'exempt' : ($completed >= $required ? 'met' : 'pending'),
                'history' => $policy->participations()->where('marketer_profile_id', $marketer->id)->orderByDesc('period')->limit(6)->get(),
            ];
        })->values();
    }

    /**
     * @return Collection<int, CrossPromoPolicy>
     */
    private function activePolicies(CarbonImmutable $at): Collection
    {
        return CrossPromoPolicy::query()->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('starts_on')->orWhereDate('starts_on', '<=', $at->toDateString()))
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $at->startOfMonth()->toDateString()))
            ->orderBy('id')->get();
    }
}
