<?php

namespace App\Domains\Campaigns\Support;

use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Campaigns\Enums\ConversionStatus;
use App\Domains\Campaigns\Enums\FeeStatus;
use App\Domains\Campaigns\Enums\InvitationSource;
use App\Domains\Campaigns\Enums\InvitationStatus;
use App\Domains\Campaigns\Models\MarketerCampaign;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Enums\WalletBucket;
use App\Domains\Finance\Models\VendorBalanceItem;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Finance\Support\Posting;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Marketers\Support\MarketingSettings;
use App\Domains\Orders\Enums\ActorType;
use App\Shared\Money\MoneyFactory;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * The only way a campaign changes status (SRS §11.5, lifecycle catalogue
 * §4.1), with history in `state_transitions`.
 *
 * Go-live (admin approve, auto-approve after 36 h, owner approving a
 * marketer request): planned influencer invitations are dispatched and the
 * quoted campaign fee is charged to the store's next statement. Reject /
 * cancel: invitations cancelled, reservations released, unshipped samples
 * cancelled, an unpaid (quoted) fee voided and a charged fee refunded per
 * `campaign_fee_refund_policy` (default none). Done (stock 0, budget used
 * up, end date, owner): pending invitations cancelled, unshipped samples
 * cancelled and every still-accepted influencer settled net of the
 * commission fee into spendable (idempotent `influencers_settled_at`).
 */
class CampaignStateMachine
{
    public function __construct(
        private StateMachine $machine,
        private Invitations $invitations,
        private Samples $samples,
        private CampaignLedger $ledger,
        private MarketingSettings $settings,
        private InventoryLedger $inventory,
        private MoneyFactory $money,
        private Clawbacks $clawbacks,
    ) {}

    public function approve(MarketerCampaign $campaign, int $adminUserId): MarketerCampaign
    {
        return $this->goLive($campaign, CampaignStatus::Active, ActorType::Admin, $adminUserId);
    }

    /**
     * ProcessCampaignAutoApproveJob: still pending after the auto-approve delay.
     */
    public function autoApproveDue(): int
    {
        $count = 0;

        MarketerCampaign::query()->where('status', CampaignStatus::PendingAdmin)->whereNotNull('auto_approve_at')->where('auto_approve_at', '<=', now())
            ->orderBy('id')->each(function (MarketerCampaign $campaign) use (&$count): void {
                $this->goLive($campaign, CampaignStatus::AutoApproved, ActorType::System, null);
                $count++;
            });

        return $count;
    }

    public function goLive(MarketerCampaign $campaign, CampaignStatus $to, ActorType $actor, ?int $userId, ?string $reason = null): MarketerCampaign
    {
        return DB::connection($campaign->getConnectionName())->transaction(function () use ($campaign, $to, $actor, $userId, $reason): MarketerCampaign {
            $this->machine->move($campaign, $to, $actor, $userId, $reason, ['live_at' => now()]);

            foreach ($campaign->planned_marketers ?? [] as $planned) {
                $marketer = MarketerProfile::query()->find($planned['marketer_profile_id']);
                $fixed = $this->money->of((string) ($planned['influencer_fixed'] ?? '0'), $campaign->currency);

                try {
                    $this->invitations->dispatch($campaign, $marketer, InvitationSource::Dispatch, true, $fixed);
                } catch (TransitionRefused) {
                    $this->unreserve($campaign, $fixed);
                }
            }

            $campaign->forceFill(['planned_marketers' => null])->save();
            $this->chargeFee($campaign);

            return $campaign;
        });
    }

    public function reject(MarketerCampaign $campaign, ActorType $actor, ?int $userId, string $reason): MarketerCampaign
    {
        if (trim($reason) === '') {
            throw new TransitionRefused(__('Give a reason for the rejection.'));
        }

        return DB::connection($campaign->getConnectionName())->transaction(function () use ($campaign, $actor, $userId, $reason): MarketerCampaign {
            $this->machine->move($campaign, CampaignStatus::Rejected, $actor, $userId, $reason, ['rejection_reason' => mb_substr($reason, 0, 255), 'done_at' => now()]);
            $this->closeDown($campaign, cancelAccepted: true);

            return $campaign;
        });
    }

    public function cancel(MarketerCampaign $campaign, ActorType $actor, ?int $userId, ?string $reason = null): MarketerCampaign
    {
        return DB::connection($campaign->getConnectionName())->transaction(function () use ($campaign, $actor, $userId, $reason): MarketerCampaign {
            $wasLive = $campaign->live_at !== null;
            $this->machine->move($campaign, CampaignStatus::Cancelled, $actor, $userId, $reason, ['done_at' => now()]);
            $this->closeDown($campaign, cancelAccepted: true);

            if ($wasLive) {
                $this->refundFeePerPolicy($campaign);
            }

            return $campaign;
        });
    }

    public function pause(MarketerCampaign $campaign, ActorType $actor, ?int $userId = null, ?string $reason = null): MarketerCampaign
    {
        return $this->machine->move($campaign, CampaignStatus::Paused, $actor, $userId, $reason, [
            'owner_paused_at' => in_array($actor, [ActorType::Vendor, ActorType::Admin], true) ? now() : $campaign->owner_paused_at,
        ]);
    }

    /**
     * Resume needs budget left and the end date not passed; only the owner
     * (or an admin) lifts an owner pause.
     */
    public function resume(MarketerCampaign $campaign, ActorType $actor, ?int $userId = null): MarketerCampaign
    {
        if ($campaign->owner_paused_at !== null && $actor === ActorType::System) {
            throw new TransitionRefused(__('Only the owner can lift an owner pause.'));
        }

        if (! $this->hasBudgetLeft($campaign) || ($campaign->ends_at !== null && $campaign->ends_at->isPast())) {
            throw new TransitionRefused(__('The campaign has no budget left or has ended.'));
        }

        return $this->machine->move($campaign, CampaignStatus::Active, $actor, $userId, null, ['owner_paused_at' => null]);
    }

    public function complete(MarketerCampaign $campaign, ActorType $actor = ActorType::System, ?int $userId = null, ?string $reason = null): MarketerCampaign
    {
        return DB::connection($campaign->getConnectionName())->transaction(function () use ($campaign, $actor, $userId, $reason): MarketerCampaign {
            $this->machine->move($campaign, CampaignStatus::Done, $actor, $userId, $reason, ['done_at' => now()]);
            $this->invitations->cancelForCampaign($campaign, includeAccepted: false);
            $this->samples->cancelOpen($campaign);
            $this->settleInfluencers($campaign);

            return $campaign;
        });
    }

    /**
     * MonitorCampaignStockJob (hourly) — product availability rules:
     * stock 0 → done; stock < minimum or listing not active → paused;
     * paused by the system with stock back, budget left and before the
     * end date → active.
     */
    public function monitorAvailability(): int
    {
        $moved = 0;

        MarketerCampaign::query()->whereIn('status', [CampaignStatus::Active, CampaignStatus::AutoApproved, CampaignStatus::Paused])->with('listing', 'category')
            ->orderBy('id')->each(function (MarketerCampaign $campaign) use (&$moved): void {
                $stock = $this->inventory->available($campaign->listing);
                $minimum = $this->minimumStock($campaign);
                $listingActive = $campaign->listing->status === ListingStatus::Active;

                if ($stock <= 0) {
                    $this->complete($campaign, reason: 'Out of stock');
                    $moved++;
                } elseif ($campaign->status->isLive() && ($stock < $minimum || ! $listingActive)) {
                    $this->pause($campaign, ActorType::System, null, $listingActive ? 'Low stock' : 'Listing not active');
                    $moved++;
                } elseif ($campaign->status === CampaignStatus::Paused && $campaign->owner_paused_at === null && $stock >= $minimum && $listingActive
                    && $this->hasBudgetLeft($campaign) && ($campaign->ends_at === null || $campaign->ends_at->isFuture())) {
                    $this->resume($campaign, ActorType::System);
                    $moved++;
                }
            });

        return $moved;
    }

    /**
     * CompleteEndedMarketerCampaignsJob (daily 04:00).
     */
    public function completeEnded(): int
    {
        $count = 0;

        MarketerCampaign::query()->whereIn('status', [CampaignStatus::Active, CampaignStatus::AutoApproved, CampaignStatus::Paused])
            ->whereNotNull('ends_at')->where('ends_at', '<=', now())
            ->orderBy('id')->each(function (MarketerCampaign $campaign) use (&$count): void {
                $this->complete($campaign, reason: 'End date passed');
                $count++;
            });

        return $count;
    }

    public function minimumStock(MarketerCampaign $campaign): int
    {
        return (int) ($campaign->category?->min_stock_for_campaign ?? $this->settings->int('min_stock_for_campaign'));
    }

    public function hasBudgetLeft(MarketerCampaign $campaign): bool
    {
        return $campaign->budget === null || $this->remainingBudget($campaign)->isPositive();
    }

    public function remainingBudget(MarketerCampaign $campaign): Money
    {
        return $this->money->of((string) ($campaign->budget ?? '0'), $campaign->currency)
            ->minus($this->money->of((string) $campaign->budget_spent, $campaign->currency))
            ->minus($this->money->of((string) $campaign->influencer_fixed_reserved, $campaign->currency));
    }

    /**
     * Pay every still-accepted influencer their fixed amount net of the
     * commission fee, into spendable (idempotent).
     */
    public function settleInfluencers(MarketerCampaign $campaign): void
    {
        $locked = MarketerCampaign::query()->lockForUpdate()->findOrFail($campaign->id);

        if ($locked->influencers_settled_at !== null) {
            return;
        }

        $currency = $locked->currency;
        $spent = $this->money->of((string) $locked->budget_spent, $currency);
        $reserved = $this->money->of((string) $locked->influencer_fixed_reserved, $currency);

        $invitations = $locked->invitations()->where('status', InvitationStatus::Accepted)->where('influencer_fixed_status', 'reserved')->get();

        foreach ($invitations as $invitation) {
            $gross = $this->money->of((string) $invitation->influencer_fixed, $currency);
            $fee = Conversions::commissionFee($gross, $invitation->rate_snapshot ?? [], $this->money);
            $net = $gross->minus($fee);
            $posting = (new Posting)
                ->debit($this->ledger->payer($locked->vendor_id), $gross)
                ->credit(AccountRef::marketer($invitation->marketer_profile_id, WalletBucket::Spendable), $net)
                ->credit(AccountRef::platform(LedgerAccountCode::FeeRevenue), $fee);
            $this->ledger->post("influencer:{$invitation->id}:settle", 'influencer_settlement', $posting, $invitation, "Influencer fee {$locked->number}");

            if ($locked->vendor_id !== null) {
                $this->ledger->balanceItem($locked->vendor_id, VendorBalanceItem::TYPE_MARKETER_COMMISSION, $invitation, "Influencer fee {$locked->number}", $gross->negated());
            }

            $invitation->forceFill(['influencer_fixed_status' => 'settled'])->save();
            $spent = $spent->plus($gross);
            $reserved = $reserved->minus($gross);
            $this->clawbacks->recover($invitation->marketer_profile_id, $currency);
        }

        $locked->forceFill([
            'influencers_settled_at' => now(),
            'budget_spent' => $this->money->toStorage($spent),
            'influencer_fixed_reserved' => $this->money->toStorage(Money::max($reserved, $this->money->zero($currency))),
        ])->save();
        $campaign->setRawAttributes($locked->getAttributes(), true);
    }

    private function chargeFee(MarketerCampaign $campaign): void
    {
        if ($campaign->fee_status !== FeeStatus::Quoted || $campaign->vendor_id === null) {
            return;
        }

        $fee = $this->money->of((string) $campaign->fee_amount, $campaign->currency);

        if ($fee->isPositive()) {
            $this->ledger->chargeVendor($campaign->vendor_id, $fee, VendorBalanceItem::TYPE_CAMPAIGN_FEE, $campaign, "campaign:{$campaign->id}:fee", "Campaign fee {$campaign->number}", AccountRef::platform(LedgerAccountCode::FeeRevenue));
        }

        $campaign->forceFill(['fee_status' => FeeStatus::Charged, 'fee_charged_at' => now()])->save();
    }

    private function closeDown(MarketerCampaign $campaign, bool $cancelAccepted): void
    {
        $this->invitations->cancelForCampaign($campaign, $cancelAccepted);
        $this->samples->cancelOpen($campaign);
        $campaign->forceFill([
            'planned_marketers' => null,
            'influencer_fixed_reserved' => '0',
            'fee_status' => $campaign->fee_status === FeeStatus::Quoted ? FeeStatus::Voided : $campaign->fee_status,
        ])->save();
    }

    /**
     * `campaign_fee_refund_policy`: none (default) | before_first_conversion
     * (full refund when nothing was converted) | pro_rata (unused share of
     * the live period up to the end date).
     */
    private function refundFeePerPolicy(MarketerCampaign $campaign): void
    {
        if ($campaign->fee_status !== FeeStatus::Charged || $campaign->vendor_id === null) {
            return;
        }

        $fee = $this->money->of((string) $campaign->fee_amount, $campaign->currency);
        $refund = match ((string) $this->settings->get('campaign_fee_refund_policy')) {
            'before_first_conversion' => $campaign->conversions()->where('status', '!=', ConversionStatus::Reversed)->exists() ? null : $fee,
            'pro_rata' => $this->proRata($campaign, $fee),
            default => null,
        };

        if ($refund === null || ! $refund->isPositive()) {
            return;
        }

        $this->ledger->creditVendor($campaign->vendor_id, $refund, VendorBalanceItem::TYPE_CAMPAIGN_FEE_REFUND, $campaign, "campaign:{$campaign->id}:fee_refund", "Campaign fee refund {$campaign->number}", AccountRef::platform(LedgerAccountCode::FeeRevenue));
        $campaign->forceFill(['fee_status' => FeeStatus::Refunded])->save();
    }

    private function proRata(MarketerCampaign $campaign, Money $fee): ?Money
    {
        if ($campaign->ends_at === null || $campaign->live_at === null || $campaign->ends_at->lessThanOrEqualTo($campaign->live_at)) {
            return null;
        }

        $total = $campaign->live_at->diffInSeconds($campaign->ends_at);
        $left = max(0, now()->diffInSeconds($campaign->ends_at, false));

        return $fee->multipliedBy(BigRational::of((int) $left)->dividedBy((int) $total), RoundingMode::HalfUp);
    }

    private function unreserve(MarketerCampaign $campaign, Money $fixed): void
    {
        $reserved = $this->money->of((string) $campaign->influencer_fixed_reserved, $campaign->currency)->minus($fixed);
        $campaign->forceFill(['influencer_fixed_reserved' => $this->money->toStorage(Money::max($reserved, $this->money->zero($campaign->currency)))])->save();
    }
}
