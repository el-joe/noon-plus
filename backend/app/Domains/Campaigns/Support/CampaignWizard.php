<?php

namespace App\Domains\Campaigns\Support;

use App\Domains\Campaigns\Enums\AttributionModel;
use App\Domains\Campaigns\Enums\CampaignOrigin;
use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Campaigns\Enums\FeeStatus;
use App\Domains\Campaigns\Enums\InvitationSource;
use App\Domains\Campaigns\Enums\InvitationStatus;
use App\Domains\Campaigns\Models\MarketerCampaign;
use App\Domains\Contracts\Support\ContractGates;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Marketers\Enums\MarketerJobType;
use App\Domains\Marketers\Enums\MarketerStatus;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Marketers\Support\MarketingSettings;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Money\MoneyFactory;
use App\Shared\StateMachine\StateMachine;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CampaignWizardService (SRS §11.4) and the other campaign origins.
 *
 * A (vendor) / C (platform) → pending_admin: source checked (ownership,
 * active listing, allowed fulfilment, stock after samples ≥ category
 * `min_stock_for_campaign`), influencers picked from the country (not on
 * another live campaign of the same listing), budget blank = unlimited
 * (end date required) or strictly greater than the influencers' fixed
 * total, collaboration contracts signed (A), fee snapshotted (A), fixed
 * amounts reserved, samples planned, auto-approve scheduled.
 * D (marketer request) → marketer_requested; the store approves with a
 * budget > 0 → active. E (peer promotion) → active at once. B (travel
 * agency) needs the travel module.
 */
class CampaignWizard
{
    public function __construct(
        private CountryContext $countryContext,
        private MarketingSettings $settings,
        private InventoryLedger $inventory,
        private Samples $samples,
        private Invitations $invitations,
        private CampaignFeeQuote $feeQuote,
        private ContractGates $contracts,
        private CampaignStateMachine $campaigns,
        private StateMachine $machine,
        private MoneyFactory $money,
    ) {}

    /**
     * @param  array{listing_id: int, title: string, notes?: string|null, ad_types?: array<int, string>|null, influencer_ids?: array<int, int>, open_to_affiliates?: bool, budget?: string|null, ends_at?: string|null, attribution_model?: string|null}  $data
     */
    public function create(CampaignOrigin $origin, array $data, ?Vendor $vendor, ?int $userId): MarketerCampaign
    {
        if ($origin === CampaignOrigin::TravelAgency) {
            throw CampaignRefused::on('listing_id', __('Travel campaigns open with the travel module.'), 'not_available');
        }

        $listing = Listing::query()->with('variant.product.category')->find($data['listing_id'] ?? 0)
            ?? throw CampaignRefused::on('listing_id', __('Choose one of your listings.'));

        if ($origin === CampaignOrigin::Vendor && ($vendor === null || $listing->vendor_id !== $vendor->id)) {
            throw CampaignRefused::on('listing_id', __('Choose one of your listings.'));
        }

        $currency = $this->countryContext->current()->currency;
        $influencers = $this->pickInfluencers($listing, $data['influencer_ids'] ?? []);
        $openToAffiliates = (bool) ($data['open_to_affiliates'] ?? false);
        $draft = new MarketerCampaign(['currency' => $currency, 'category_id' => $listing->variant->product->category_id, 'open_to_affiliates' => $openToAffiliates]);
        $fixed = $influencers->mapWithKeys(fn (MarketerProfile $marketer): array => [$marketer->id => $this->invitations->influencerFixed($marketer, $draft)]);
        $fixedTotal = $fixed->reduce(fn (Money $sum, Money $amount): Money => $sum->plus($amount), $this->money->zero($currency));
        [$budget, $endsAt] = $this->budget($data, $fixedTotal, $currency);

        $this->assertSource($listing, $this->samples->plannedUnits($draft, $influencers->count()));

        if ($origin === CampaignOrigin::Vendor) {
            $types = array_merge($influencers->isNotEmpty() ? ['influencer'] : [], $openToAffiliates ? ['affiliate', 'broker'] : []);

            if ($this->contracts->missingCollaboration($vendor, $types)->isNotEmpty()) {
                throw CampaignRefused::on('contract', __('Sign the collaboration contract for these marketers first.'), 'contract_required');
            }
        }

        $quote = $origin === CampaignOrigin::Vendor ? $this->quote($listing, $budget, $fixedTotal) : null;

        return DB::connection($listing->getConnectionName())->transaction(function () use ($origin, $data, $vendor, $userId, $listing, $influencers, $fixed, $fixedTotal, $budget, $endsAt, $openToAffiliates, $quote, $currency): MarketerCampaign {
            $campaign = MarketerCampaign::query()->create([
                'number' => 'PENDING-'.uniqid(),
                'origin' => $origin,
                'owner_type' => $origin === CampaignOrigin::Vendor ? MarketerCampaign::OWNER_VENDOR : MarketerCampaign::OWNER_PLATFORM,
                'owner_id' => $vendor?->id,
                'vendor_id' => $origin === CampaignOrigin::Vendor ? $vendor->id : null,
                'listing_id' => $listing->id,
                'category_id' => $listing->variant->product->category_id,
                'title' => $data['title'],
                'notes' => $data['notes'] ?? null,
                'ad_types' => $data['ad_types'] ?? [],
                'planned_marketers' => $influencers->map(fn (MarketerProfile $marketer): array => ['marketer_profile_id' => $marketer->id, 'influencer_fixed' => (string) $fixed[$marketer->id]->getAmount()])->values()->all(),
                'open_to_affiliates' => $openToAffiliates,
                'attribution_model' => AttributionModel::tryFrom((string) ($data['attribution_model'] ?? ''))?->value,
                'currency' => $currency,
                'budget' => $budget === null ? null : $this->money->toStorage($budget),
                'influencer_fixed_reserved' => $this->money->toStorage($fixedTotal),
                'ends_at' => $endsAt,
                'status' => CampaignStatus::PendingAdmin,
                'auto_approve_at' => now()->addHours($this->settings->int('auto_approve_hours')),
                'fee_amount' => $quote['fee'] ?? '0',
                'fee_snapshot' => $quote,
                'fee_status' => $quote === null ? FeeStatus::None : FeeStatus::Quoted,
                'created_by_user_id' => $userId,
            ]);
            $this->number($campaign);
            $this->machine->created($campaign, $origin === CampaignOrigin::Vendor ? ActorType::Vendor : ActorType::Admin, $userId);
            $this->samples->planForCampaign($campaign);

            return $campaign;
        });
    }

    /**
     * Live fee quote for the wizard (`/quote`).
     *
     * @return array<string, mixed>
     */
    public function quote(Listing $listing, ?Money $budget, Money $influencerFixedTotal): array
    {
        return $this->feeQuote->quote($this->countryContext->current()->currency, $listing->variant->product->category, (string) $listing->fulfilment, $budget, $influencerFixedTotal);
    }

    /**
     * Quote from raw wizard input (no campaign is created).
     *
     * @param  array<int, int>  $influencerIds
     * @return array<string, mixed>
     */
    public function quoteFor(Listing $listing, ?string $budget, array $influencerIds): array
    {
        $currency = $this->countryContext->current()->currency;
        $draft = new MarketerCampaign(['currency' => $currency, 'category_id' => $listing->variant->product->category_id]);
        $fixedTotal = MarketerProfile::query()->whereKey($influencerIds)->get()
            ->reduce(fn (Money $sum, MarketerProfile $marketer): Money => $sum->plus($this->invitations->influencerFixed($marketer, $draft)), $this->money->zero($currency));
        $budgetMoney = $budget === null || bccomp($budget, '0', 4) <= 0 ? null : $this->money->of($budget, $currency);

        return $this->quote($listing, $budgetMoney, $fixedTotal) + ['influencer_fixed_total' => (string) $fixedTotal->getAmount(), 'currency' => $currency];
    }

    /**
     * Origin D: a marketer asks to promote a store's listing.
     */
    public function requestByMarketer(MarketerProfile $marketer, Listing $listing, string $title, ?string $notes = null, ?int $userId = null): MarketerCampaign
    {
        $this->assertCanTakeCampaigns($marketer);

        if ($listing->status !== ListingStatus::Active) {
            throw CampaignRefused::on('listing_id', __('This listing is not on sale.'));
        }

        $campaign = MarketerCampaign::query()->create([
            'number' => 'PENDING-'.uniqid(),
            'origin' => CampaignOrigin::MarketerRequest,
            'owner_type' => MarketerCampaign::OWNER_VENDOR,
            'owner_id' => $listing->vendor_id,
            'vendor_id' => $listing->vendor_id,
            'requested_by_marketer_id' => $marketer->id,
            'listing_id' => $listing->id,
            'category_id' => $listing->variant->product->category_id,
            'title' => $title,
            'notes' => $notes,
            'currency' => $this->countryContext->current()->currency,
            'status' => CampaignStatus::MarketerRequested,
            'fee_status' => FeeStatus::None,
            'created_by_user_id' => $userId,
        ]);
        $this->number($campaign);
        $this->machine->created($campaign, ActorType::Marketer, $userId);

        return $campaign;
    }

    /**
     * The store approves a marketer request: sets the budget (> 0), the fee
     * is snapshotted, an influencer requester's fixed amount reserved, the
     * requester's invitation dispatched and auto-accepted, then go-live.
     */
    public function approveRequest(MarketerCampaign $campaign, string $budget, ?int $userId = null, ?string $endsAt = null): MarketerCampaign
    {
        if ($campaign->status !== CampaignStatus::MarketerRequested) {
            throw CampaignRefused::on('budget', __('This request was already handled.'));
        }

        if (bccomp($budget, '0', 4) <= 0) {
            throw CampaignRefused::on('budget', __('Set a commission budget above zero.'));
        }

        $requester = $campaign->requester;
        $budgetMoney = $this->money->of($budget, $campaign->currency);
        $fixed = $requester->perSaleType() === MarketerJobType::Influencer ? $this->invitations->influencerFixed($requester, $campaign) : $this->money->zero($campaign->currency);

        if ($fixed->isPositive() && ! $budgetMoney->isGreaterThan($fixed)) {
            throw CampaignRefused::on('budget', __('The budget must be greater than the influencer fixed amount (:amount).', ['amount' => (string) $fixed->getAmount()]));
        }

        return DB::connection($campaign->getConnectionName())->transaction(function () use ($campaign, $budgetMoney, $fixed, $requester, $userId, $endsAt): MarketerCampaign {
            $quote = $this->quote($campaign->listing, $budgetMoney, $fixed);
            $campaign->forceFill([
                'budget' => $this->money->toStorage($budgetMoney),
                'ends_at' => $endsAt === null ? null : CarbonImmutable::parse($endsAt, $this->countryContext->current()->timezone)->utc(),
                'fee_amount' => $quote['fee'],
                'fee_snapshot' => $quote,
                'fee_status' => FeeStatus::Quoted,
            ])->save();

            $this->campaigns->goLive($campaign, CampaignStatus::Active, ActorType::Vendor, $userId, 'Marketer request approved');

            if ($fixed->isPositive()) {
                $this->invitations->reserve($campaign, $fixed);
            }

            $this->invitations->dispatch($campaign, $requester, InvitationSource::Request, $fixed->isPositive(), $fixed->isPositive() ? $fixed : null);

            return $campaign->refresh();
        });
    }

    /**
     * Origin E: an influencer promotes another influencer's own FBN listing.
     */
    public function peerPromotion(MarketerProfile $promoter, Listing $listing, ?int $userId = null): MarketerCampaign
    {
        $this->assertCanTakeCampaigns($promoter);

        $owner = MarketerProfile::query()->where('vendor_id', $listing->vendor_id)->first();

        if ($owner === null || ! $owner->hasJobType(MarketerJobType::Influencer) || ! $promoter->hasJobType(MarketerJobType::Influencer)) {
            throw CampaignRefused::on('listing_id', __('Peer promotion is between two influencers on the owner\'s own listing.'));
        }

        if ($owner->id === $promoter->id) {
            throw CampaignRefused::on('listing_id', __('You cannot promote your own listing.'));
        }

        if ($listing->fulfilment !== 'fbn' || $listing->status !== ListingStatus::Active || $this->inventory->available($listing) < $this->settings->int('peer_min_stock')) {
            throw CampaignRefused::on('listing_id', __('Only active Fulfilled-by-Nawy listings with stock can be peer-promoted.'));
        }

        return DB::connection($listing->getConnectionName())->transaction(function () use ($promoter, $owner, $listing, $userId): MarketerCampaign {
            MarketerProfile::query()->lockForUpdate()->findOrFail($promoter->id);
            $thisMonth = CarbonImmutable::now($this->countryContext->current()->timezone)->startOfMonth()->utc();
            $used = MarketerCampaign::query()
                ->where('origin', CampaignOrigin::Peer)
                ->where('requested_by_marketer_id', $promoter->id)
                ->where('owner_id', $owner->id)
                ->where('created_at', '>=', $thisMonth)
                ->whereNotIn('status', [CampaignStatus::Rejected, CampaignStatus::Cancelled])
                ->count();

            if ($used >= $this->settings->int('peer_per_owner_per_month')) {
                throw CampaignRefused::on('listing_id', __('You already promote this influencer this month.'));
            }

            $campaign = MarketerCampaign::query()->create([
                'number' => 'PENDING-'.uniqid(),
                'origin' => CampaignOrigin::Peer,
                'owner_type' => MarketerCampaign::OWNER_MARKETER,
                'owner_id' => $owner->id,
                'vendor_id' => $listing->vendor_id,
                'requested_by_marketer_id' => $promoter->id,
                'listing_id' => $listing->id,
                'category_id' => $listing->variant->product->category_id,
                'title' => __('Peer promotion by :name', ['name' => $promoter->display_name]),
                'currency' => $this->countryContext->current()->currency,
                'status' => CampaignStatus::Active,
                'live_at' => now(),
                'fee_status' => FeeStatus::None,
                'created_by_user_id' => $userId,
            ]);
            $this->number($campaign);
            $this->machine->created($campaign, ActorType::Marketer, $userId);
            $this->invitations->dispatch($campaign, $promoter, InvitationSource::Peer);

            return $campaign;
        });
    }

    /**
     * FR-MKT-1: active and current onboarding contract accepted.
     */
    public function assertCanTakeCampaigns(MarketerProfile $marketer): void
    {
        if ($marketer->global_status !== MarketerStatus::Active) {
            throw CampaignRefused::on('marketer', __('Your marketer account must be active to take campaigns.'));
        }

        if (! $this->contracts->marketerContractAccepted($marketer)) {
            throw CampaignRefused::on('marketer', __('Accept the current marketer contract before taking campaigns.'), 'contract_required');
        }
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, MarketerProfile>
     */
    private function pickInfluencers(Listing $listing, array $ids): Collection
    {
        $influencers = MarketerProfile::query()->whereKey(array_unique($ids))->get();

        if ($influencers->count() !== count(array_unique($ids))) {
            throw CampaignRefused::on('influencer_ids', __('Pick influencers of this country.'));
        }

        foreach ($influencers as $influencer) {
            if (! $influencer->isActive() || ! $influencer->hasJobType(MarketerJobType::Influencer)) {
                throw CampaignRefused::on('influencer_ids', __(':name cannot be picked as an influencer.', ['name' => $influencer->display_name]));
            }

            $busy = MarketerCampaign::query()->where('listing_id', $listing->id)
                ->whereNotIn('status', [CampaignStatus::Done, CampaignStatus::Cancelled, CampaignStatus::Rejected])
                ->where(fn ($query) => $query
                    ->whereJsonContains('planned_marketers', ['marketer_profile_id' => $influencer->id])
                    ->orWhereHas('invitations', fn ($inner) => $inner->where('marketer_profile_id', $influencer->id)->whereIn('status', [InvitationStatus::Pending, InvitationStatus::Accepted])))
                ->exists();

            if ($busy) {
                throw CampaignRefused::on('influencer_ids', __(':name already promotes this listing on another campaign.', ['name' => $influencer->display_name]));
            }
        }

        return $influencers;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: Money|null, 1: string|null}
     */
    private function budget(array $data, Money $fixedTotal, string $currency): array
    {
        $raw = $data['budget'] ?? null;
        $endsAt = $data['ends_at'] ?? null;
        $budget = $raw === null || $raw === '' || bccomp((string) $raw, '0', 4) <= 0 ? null : $this->money->of((string) $raw, $currency);

        if ($budget === null && blank($endsAt)) {
            throw CampaignRefused::on('ends_at', __('An unlimited budget needs an end date.'));
        }

        if ($budget !== null && ! $budget->isGreaterThan($fixedTotal)) {
            throw CampaignRefused::on('budget', __('The budget must be greater than the influencers\' fixed total (:amount).', ['amount' => (string) $fixedTotal->getAmount()]));
        }

        $timezone = $this->countryContext->current()->timezone;

        if (! blank($endsAt) && CarbonImmutable::parse($endsAt, $timezone)->isPast()) {
            throw CampaignRefused::on('ends_at', __('The end date must be in the future.'));
        }

        // Entered in the country's local time (ADR 14), stored in UTC.
        return [$budget, blank($endsAt) ? null : CarbonImmutable::parse($endsAt, $timezone)->utc()->toDateTimeString()];
    }

    private function assertSource(Listing $listing, int $sampleUnits): void
    {
        if ($listing->status !== ListingStatus::Active) {
            throw CampaignRefused::on('listing_id', __('Only active listings can be promoted.'));
        }

        $allowed = (array) $this->settings->get('allowed_fulfilment_models');

        if (! in_array($listing->fulfilment, $allowed, true)) {
            throw CampaignRefused::on('listing_id', __('This fulfilment model cannot host campaigns.'));
        }

        $category = $listing->variant->product->category;
        $minimum = (int) ($category?->min_stock_for_campaign ?? $this->settings->int('min_stock_for_campaign'));

        if ($this->inventory->available($listing) - $sampleUnits < $minimum) {
            throw CampaignRefused::on('listing_id', __('Stock after samples must be at least :min units.', ['min' => $minimum]));
        }
    }

    private function number(MarketerCampaign $campaign): void
    {
        $campaign->forceFill(['number' => sprintf('MC-%s-%06d', $this->countryContext->current()->iso2, $campaign->id)])->save();
    }
}
