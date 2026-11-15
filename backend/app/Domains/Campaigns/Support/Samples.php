<?php

namespace App\Domains\Campaigns\Support;

use App\Domains\Campaigns\Enums\SampleStatus;
use App\Domains\Campaigns\Models\CampaignInvitation;
use App\Domains\Campaigns\Models\CampaignSample;
use App\Domains\Campaigns\Models\MarketerCampaign;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Models\VendorBalanceItem;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Finance\Support\CommissionRates;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Marketers\Support\MarketingSettings;
use App\Domains\Orders\Enums\ActorType;
use App\Shared\Money\MoneyFactory;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Support\Facades\DB;

/**
 * Promotion samples (SRS §11.9). Planned with the campaign (one platform
 * sample, one affiliate row when affiliates are targeted) and per
 * influencer at dispatch. The marketer gives an address (requested), the
 * admin or owner ships it — the units leave stock like a sale and the
 * platform commission on price × quantity is charged to the store's next
 * statement as a zero-priced line — then delivered; the marketer confirms
 * receipt (review due in `marketer.sample_review_days`) or it comes back
 * (returned, units restocked). Unshipped samples are cancelled when the
 * campaign closes.
 */
class Samples
{
    public function __construct(
        private StateMachine $machine,
        private MarketingSettings $settings,
        private InventoryLedger $inventory,
        private CommissionRates $commissionRates,
        private CampaignLedger $ledger,
        private MoneyFactory $money,
        private CountryContext $countryContext,
    ) {}

    /**
     * Units the campaign will need for samples (wizard stock guard).
     */
    public function plannedUnits(MarketerCampaign $campaign, int $influencers): int
    {
        return $this->platformQuantity($campaign) + $influencers * $this->influencerQuantity($campaign) + ($campaign->open_to_affiliates ? $this->affiliateQuantity($campaign) : 0);
    }

    public function planForCampaign(MarketerCampaign $campaign): void
    {
        $this->create($campaign, null, 'platform', $this->platformQuantity($campaign));

        if ($campaign->open_to_affiliates) {
            $this->create($campaign, null, 'affiliate', $this->affiliateQuantity($campaign));
        }
    }

    public function planForInvitation(CampaignInvitation $invitation): CampaignSample
    {
        return $this->create($invitation->campaign, $invitation, 'influencer', $this->influencerQuantity($invitation->campaign));
    }

    /**
     * @param  array<string, string>  $address
     */
    public function request(CampaignSample $sample, int $marketerProfileId, array $address, ?int $userId = null): CampaignSample
    {
        if ($sample->marketer_profile_id !== $marketerProfileId) {
            abort(404);
        }

        return $this->machine->move($sample, SampleStatus::Requested, ActorType::Marketer, $userId, null, ['address' => $address]);
    }

    public function ship(CampaignSample $sample, ActorType $actor, ?int $userId = null, ?string $trackingNumber = null): CampaignSample
    {
        return DB::connection($sample->getConnectionName())->transaction(function () use ($sample, $actor, $userId, $trackingNumber): CampaignSample {
            $campaign = $sample->campaign()->with('listing.variant.product')->firstOrFail();
            $listing = $campaign->listing;

            if ($this->inventory->available($listing) < $sample->quantity) {
                throw new TransitionRefused(__('Not enough stock to ship this sample.'));
            }

            $this->machine->move($sample, SampleStatus::Shipped, $actor, $userId, null, ['shipped_at' => now(), 'tracking_number' => $trackingNumber]);
            $this->inventory->move($listing, -$sample->quantity, 'campaign_sample', 'campaign_sample', $sample->id, $userId);

            if ($campaign->vendor_id !== null) {
                $currency = $this->countryContext->current()->currency;
                $categoryId = (int) $listing->variant->product->category_id;
                $rate = (string) $this->commissionRates->forCategories([$categoryId])[$categoryId];
                $value = $this->money->of($listing->effectivePrice(), $currency)->multipliedBy($sample->quantity);
                $commission = $this->money->percentageOf($value, $rate);
                $sample->forceFill(['commission_amount' => $this->money->toStorage($commission)])->save();
                $this->ledger->chargeVendor($campaign->vendor_id, $commission, VendorBalanceItem::TYPE_SAMPLE_COMMISSION, $sample, "sample:{$sample->id}:commission", "Sample commission {$campaign->number} ×{$sample->quantity}", AccountRef::platform(LedgerAccountCode::CommissionRevenue));
            }

            return $sample;
        });
    }

    public function deliver(CampaignSample $sample, ActorType $actor, ?int $userId = null): CampaignSample
    {
        return $this->machine->move($sample, SampleStatus::Delivered, $actor, $userId, null, ['delivered_at' => now()]);
    }

    public function confirmReceipt(CampaignSample $sample, int $marketerProfileId, ?int $userId = null): CampaignSample
    {
        if ($sample->marketer_profile_id !== $marketerProfileId) {
            abort(404);
        }

        return $this->machine->move($sample, SampleStatus::ReviewDue, ActorType::Marketer, $userId, null, [
            'review_due_at' => now()->addDays($this->settings->int('sample_review_days')),
        ]);
    }

    public function markReturned(CampaignSample $sample, ActorType $actor, ?int $userId = null): CampaignSample
    {
        return DB::connection($sample->getConnectionName())->transaction(function () use ($sample, $actor, $userId): CampaignSample {
            $this->machine->move($sample, SampleStatus::Returned, $actor, $userId, null, ['returned_at' => now()]);
            $this->inventory->move($sample->campaign->listing, $sample->quantity, 'campaign_sample_returned', 'campaign_sample', $sample->id, $userId);

            return $sample;
        });
    }

    /**
     * Unshipped samples of a closed campaign are cancelled.
     */
    public function cancelOpen(MarketerCampaign $campaign, ?int $invitationId = null): void
    {
        $campaign->samples()->whereIn('status', [SampleStatus::Planned, SampleStatus::Requested])
            ->when($invitationId !== null, fn ($query) => $query->where('invitation_id', $invitationId))
            ->get()
            ->each(fn (CampaignSample $sample) => $this->machine->move($sample, SampleStatus::Cancelled, ActorType::System));
    }

    /**
     * Hand an unshipped influencer sample over to the replacement marketer.
     */
    public function handOver(CampaignInvitation $from, CampaignInvitation $to): bool
    {
        return CampaignSample::query()->where('invitation_id', $from->id)->where('status', SampleStatus::Planned)
            ->update(['invitation_id' => $to->id, 'marketer_profile_id' => $to->marketer_profile_id]) > 0;
    }

    private function create(MarketerCampaign $campaign, ?CampaignInvitation $invitation, string $kind, int $quantity): CampaignSample
    {
        $sample = CampaignSample::query()->create([
            'campaign_id' => $campaign->id,
            'invitation_id' => $invitation?->id,
            'marketer_profile_id' => $invitation?->marketer_profile_id,
            'kind' => $kind,
            'quantity' => $quantity,
            'status' => SampleStatus::Planned,
        ]);
        $this->machine->created($sample, ActorType::System);

        return $sample;
    }

    private function platformQuantity(MarketerCampaign $campaign): int
    {
        return max((int) ($campaign->category?->platform_sample_qty ?? 1), $this->settings->int('min_platform_sample_qty'));
    }

    private function influencerQuantity(MarketerCampaign $campaign): int
    {
        return max((int) ($campaign->category?->influencer_sample_qty ?? 1), $this->settings->int('min_influencer_sample_qty'));
    }

    private function affiliateQuantity(MarketerCampaign $campaign): int
    {
        return max(1, (int) ($campaign->category?->affiliate_sample_qty ?? 1));
    }
}
