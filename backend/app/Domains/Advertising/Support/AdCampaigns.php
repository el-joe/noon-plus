<?php

namespace App\Domains\Advertising\Support;

use App\Domains\Advertising\Enums\AdCampaignStatus;
use App\Domains\Advertising\Models\AdCampaign;
use App\Domains\Advertising\Models\AdEvent;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Models\LedgerTransaction;
use App\Domains\Finance\Models\VendorBalanceItem;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Finance\Support\Ledger;
use App\Domains\Finance\Support\Posting;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\Listing;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Money\MoneyFactory;
use App\Shared\Settings\VerticalSettings;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Self-serve sponsored-product campaigns (SRS §10, lifecycle catalogue
 * §4.7). A store promotes one of its live listings with a CPC or CPM bid,
 * a daily budget (≥ `ad_campaign.min_daily_budget`) and an optional total
 * budget, targeting keywords and/or categories (none = automatic).
 *
 * Pacing: every billable event locks the campaign row; the cost (CPC bid
 * per click, CPM bid ÷ 1000 per impression) is only taken while it fits
 * the day's and the total budget — the event that would overspend is kept
 * unbilled and the campaign moves to `budget_exhausted`. A repeated click
 * from the same visitor within `click_dedupe_minutes` is invalid (not
 * billed). Spend is billed once a day (ResetAdCampaignDailyBudgetJob at
 * 00:00): Dr store spendable / Cr ad revenue with a statement line; daily
 * caps reset and campaigns stopped only by the daily cap resume.
 */
class AdCampaigns
{
    public function __construct(
        private StateMachine $machine,
        private CountryContext $countryContext,
        private MoneyFactory $money,
        private VerticalSettings $settings,
        private Ledger $ledger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Vendor $vendor, array $data, int $userId): AdCampaign
    {
        $listing = Listing::query()->where('vendor_id', $vendor->id)->find($data['listing_id'] ?? 0)
            ?? throw new TransitionRefused(__('Choose one of your listings.'));
        $currency = $this->countryContext->current()->currency;
        $campaign = new AdCampaign([
            'number' => 'AD-'.uniqid(),
            'advertiser_type' => AdCampaign::ADVERTISER_VENDOR,
            'advertiser_id' => $vendor->id,
            'listing_id' => $listing->id,
            'name' => (string) ($data['name'] ?? $listing->variant->product->title_en),
            'pricing_model' => ($data['pricing_model'] ?? 'cpc') === 'cpm' ? 'cpm' : 'cpc',
            'bid' => $this->money->toStorage($this->money->of((string) ($data['bid'] ?? '0'), $currency)),
            'currency' => $currency,
            'daily_budget' => $this->money->toStorage($this->money->of((string) ($data['daily_budget'] ?? '0'), $currency)),
            'total_budget' => empty($data['total_budget']) ? null : $this->money->toStorage($this->money->of((string) $data['total_budget'], $currency)),
            'keywords' => array_values(array_filter(array_map(fn ($word): string => mb_strtolower(trim((string) $word)), (array) ($data['keywords'] ?? [])))),
            'category_ids' => array_values(array_map('intval', (array) ($data['category_ids'] ?? []))),
            'starts_on' => $data['starts_on'] ?? null,
            'ends_on' => $data['ends_on'] ?? null,
        ]);
        $campaign->forceFill(['status' => AdCampaignStatus::Draft, 'spend_date' => today()])->save();
        $campaign->forceFill(['number' => 'AD-'.$this->countryContext->current()->iso2.'-'.str_pad((string) $campaign->id, 6, '0', STR_PAD_LEFT)])->save();
        $this->machine->created($campaign, ActorType::Vendor, $userId);

        return $campaign;
    }

    public function submit(AdCampaign $campaign, int $userId): AdCampaign
    {
        $minBid = $this->settings->string($campaign->pricing_model === 'cpm' ? 'advertising.min_cpm_bid' : 'advertising.min_cpc_bid');

        if (bccomp((string) $campaign->bid, $minBid, 4) < 0) {
            throw new TransitionRefused(__('The bid must be at least :min.', ['min' => $minBid]));
        }

        if (bccomp((string) $campaign->daily_budget, $this->settings->string('advertising.min_daily_budget'), 4) < 0) {
            throw new TransitionRefused(__('The daily budget must be at least :min.', ['min' => $this->settings->string('advertising.min_daily_budget')]));
        }

        if ($campaign->total_budget !== null && bccomp((string) $campaign->total_budget, (string) $campaign->daily_budget, 4) < 0) {
            throw new TransitionRefused(__('The total budget cannot be below the daily budget.'));
        }

        if ($campaign->listing->status !== ListingStatus::Active) {
            throw new TransitionRefused(__('Only a live listing can be advertised.'));
        }

        return $this->machine->move($campaign, AdCampaignStatus::PendingReview, ActorType::Vendor, $userId);
    }

    public function approve(AdCampaign $campaign, int $adminUserId): AdCampaign
    {
        return $this->machine->move($campaign, AdCampaignStatus::Active, ActorType::Admin, $adminUserId, 'Approved');
    }

    public function reject(AdCampaign $campaign, int $adminUserId, string $reason): AdCampaign
    {
        return $this->machine->move($campaign, AdCampaignStatus::Rejected, ActorType::Admin, $adminUserId, $reason, ['rejection_reason' => mb_substr($reason, 0, 255)]);
    }

    public function move(AdCampaign $campaign, AdCampaignStatus $to, ActorType $actor, ?int $userId): AdCampaign
    {
        if ($to === AdCampaignStatus::Active && $campaign->status === AdCampaignStatus::BudgetExhausted) {
            throw new TransitionRefused(__('The budget is spent; it resumes with the next day or a higher budget.'));
        }

        return $this->machine->move($campaign, $to, $actor, $userId);
    }

    public function impression(AdCampaign $campaign, string $placement, ?string $visitorKey): AdEvent
    {
        $cost = $campaign->pricing_model === 'cpm'
            ? $this->money->of((string) $campaign->bid, $campaign->currency)->dividedBy(1000, RoundingMode::HalfUp)
            : $this->money->zero($campaign->currency);

        return $this->record($campaign, 'impression', $placement, $visitorKey, $cost);
    }

    public function click(AdCampaign $campaign, string $placement, ?string $visitorKey): AdEvent
    {
        $cost = $campaign->pricing_model === 'cpc' ? $this->money->of((string) $campaign->bid, $campaign->currency) : $this->money->zero($campaign->currency);

        return $this->record($campaign, 'click', $placement, $visitorKey, $cost);
    }

    /**
     * ResetAdCampaignDailyBudgetJob (00:00): bill yesterday's spend, reset
     * the daily caps, resume campaigns stopped by the daily cap only and end
     * those past their end date.
     *
     * @return array{billed: int, resumed: int, ended: int}
     */
    public function resetDaily(): array
    {
        $result = ['billed' => 0, 'resumed' => 0, 'ended' => 0];

        AdCampaign::query()->whereNotIn('status', [AdCampaignStatus::Draft, AdCampaignStatus::Rejected])->orderBy('id')->each(function (AdCampaign $campaign) use (&$result): void {
            DB::connection($campaign->getConnectionName())->transaction(function () use ($campaign, &$result): void {
                $locked = AdCampaign::query()->lockForUpdate()->findOrFail($campaign->id);
                $result['billed'] += $this->bill($locked) ? 1 : 0;
                $locked->forceFill(['spent_today' => 0, 'spend_date' => today()])->save();

                if (in_array($locked->status, [AdCampaignStatus::Active, AdCampaignStatus::Paused, AdCampaignStatus::BudgetExhausted], true) && $locked->ends_on !== null && $locked->ends_on->lt(today())) {
                    $this->machine->move($locked, AdCampaignStatus::Ended, ActorType::System, null, 'End date passed');
                    $result['ended']++;
                } elseif ($locked->status === AdCampaignStatus::BudgetExhausted && ! $locked->total_exhausted) {
                    $this->machine->move($locked, AdCampaignStatus::Active, ActorType::System, null, 'Daily budget reset');
                    $result['resumed']++;
                }
            });
        });

        return $result;
    }

    /**
     * Post the campaign's unbilled spend to the advertiser (once per spend day).
     */
    public function bill(AdCampaign $campaign): bool
    {
        $amount = $this->money->of((string) $campaign->unbilled_spend, $campaign->currency);

        if (! $amount->isPositive()) {
            return false;
        }

        $key = "ad-spend:{$campaign->id}:".($campaign->spend_date?->toDateString() ?? today()->toDateString());

        if (! LedgerTransaction::query()->where('idempotency_key', $key)->exists()) {
            $transaction = $this->ledger->post($key, VendorBalanceItem::TYPE_AD_SPEND, (new Posting)
                ->debit(AccountRef::vendor((int) $campaign->advertiser_id), $amount)
                ->credit(AccountRef::platform(LedgerAccountCode::AdRevenue), $amount), reference: $campaign, description: "Ad spend {$campaign->number}");
            VendorBalanceItem::query()->create([
                'vendor_id' => $campaign->advertiser_id,
                'type' => VendorBalanceItem::TYPE_AD_SPEND,
                'reference_type' => 'ledger_transaction',
                'reference_id' => $transaction->id,
                'description' => mb_substr("Ad spend {$campaign->number} ".($campaign->spend_date?->toDateString() ?? ''), 0, 255),
                'amount' => $this->money->toStorage($amount->negated()),
                'is_eligible' => true,
            ]);
        }

        $campaign->forceFill(['unbilled_spend' => 0])->save();

        return true;
    }

    /**
     * Is the campaign servable now (active, inside its dates)?
     */
    public function isServable(AdCampaign $campaign): bool
    {
        return $campaign->status === AdCampaignStatus::Active
            && ($campaign->starts_on === null || $campaign->starts_on->lte(today()))
            && ($campaign->ends_on === null || $campaign->ends_on->gte(today()));
    }

    private function record(AdCampaign $campaign, string $type, string $placement, ?string $visitorKey, Money $cost): AdEvent
    {
        return DB::connection($campaign->getConnectionName())->transaction(function () use ($campaign, $type, $placement, $visitorKey, $cost): AdEvent {
            $locked = AdCampaign::query()->lockForUpdate()->findOrFail($campaign->id);

            if ($locked->spend_date === null || ! $locked->spend_date->isSameDay(today())) {
                $this->bill($locked);
                $locked->forceFill(['spent_today' => 0, 'spend_date' => today()])->save();
            }

            $invalid = match (true) {
                ! $this->isServable($locked) => 'not_active',
                $visitorKey !== null && $this->isDuplicate($locked, $type, $visitorKey) => 'duplicate',
                default => null,
            };
            $billable = $invalid === null && $cost->isPositive();

            if ($billable) {
                $currency = $locked->currency;
                $spentToday = $this->money->of((string) $locked->spent_today, $currency)->plus($cost);
                $spentTotal = $this->money->of((string) $locked->spent_total, $currency)->plus($cost);
                $overDaily = $spentToday->isGreaterThan($this->money->of((string) $locked->daily_budget, $currency));
                $overTotal = $locked->total_budget !== null && $spentTotal->isGreaterThan($this->money->of((string) $locked->total_budget, $currency));

                if ($overDaily || $overTotal) {
                    $billable = false;
                    $invalid = $overTotal ? 'total_budget' : 'daily_budget';
                    $this->machine->move($locked, AdCampaignStatus::BudgetExhausted, ActorType::System, null, $overTotal ? 'Total budget spent' : 'Daily budget spent', ['total_exhausted' => $overTotal]);
                } else {
                    $locked->forceFill([
                        'spent_today' => $this->money->toStorage($spentToday),
                        'spent_total' => $this->money->toStorage($spentTotal),
                        'unbilled_spend' => $this->money->toStorage($this->money->of((string) $locked->unbilled_spend, $currency)->plus($cost)),
                    ]);
                }
            }

            if ($invalid === null || $invalid === 'daily_budget' || $invalid === 'total_budget') {
                $locked->forceFill([$type === 'click' ? 'clicks' : 'impressions' => $locked->{$type === 'click' ? 'clicks' : 'impressions'} + ($invalid === null ? 1 : 0)]);
            }

            $locked->save();
            $campaign->setRawAttributes($locked->getAttributes(), true);

            return AdEvent::query()->create([
                'ad_campaign_id' => $locked->id,
                'type' => $type,
                'placement' => mb_substr($placement, 0, 24),
                'visitor_key' => $visitorKey === null ? null : mb_substr($visitorKey, 0, 64),
                'cost' => $billable ? $this->money->toStorage($cost) : '0',
                'is_billable' => $billable,
                'invalid_reason' => $invalid,
            ]);
        });
    }

    private function isDuplicate(AdCampaign $campaign, string $type, string $visitorKey): bool
    {
        $window = $type === 'click' ? $this->settings->int('advertising.click_dedupe_minutes') : 1;

        return AdEvent::query()->where('ad_campaign_id', $campaign->id)->where('type', $type)->where('visitor_key', $visitorKey)
            ->where('created_at', '>=', now()->subMinutes($window))->exists();
    }
}
