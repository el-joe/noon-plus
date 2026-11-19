<?php

namespace App\Domains\Marketers\Support;

use App\Domains\Campaigns\Enums\InvitationStatus;
use App\Domains\Campaigns\Models\CampaignInvitation;
use App\Domains\Campaigns\Models\MarketerConversion;
use App\Domains\Campaigns\Models\ReferralClick;
use App\Domains\Catalog\Support\ListingCards;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\Listing;
use App\Domains\Marketers\Models\MarketerPick;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Shared\Settings\VerticalSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A creator's product picks (Creator Studio → My products, creator page
 * `/creators/{slug}`, referral landing `/r/{code}`): the creator's own
 * curated picks in their order, then the listings of their live accepted
 * campaigns not already picked. A pick carries the creator's referral code
 * when one of their live campaigns promotes that listing; otherwise it has
 * no code (no attribution, so no clicks or conversions).
 */
class CreatorPicks
{
    /** @var array<int, string> campaign statuses whose links are live */
    public const LIVE_CAMPAIGN = ['active', 'auto_approved'];

    public function __construct(private VerticalSettings $settings) {}

    public function max(): int
    {
        return max(1, $this->settings->int('creators.max_picks'));
    }

    /**
     * Public picks: active listings only, curated first.
     *
     * @return Collection<int, array{listing: Listing, referral_code: string|null}>
     */
    public function public(MarketerProfile $creator, int $limit, ?int $exceptInvitationId = null): Collection
    {
        $invitations = $this->liveInvitations($creator)->when($exceptInvitationId !== null, fn (Collection $rows) => $rows->reject(fn (CampaignInvitation $invitation): bool => $invitation->id === $exceptInvitationId));
        $codes = $invitations->mapWithKeys(fn (CampaignInvitation $invitation): array => [$invitation->campaign->listing_id => $invitation->referral_code]);

        $curated = $creator->picks()->with(['listing' => fn ($query) => $query->with(ListingCards::relations())])->get()
            ->pluck('listing')->filter(fn (?Listing $listing): bool => $listing !== null && $listing->status === ListingStatus::Active);
        $fromCampaigns = $invitations->map(fn (CampaignInvitation $invitation): ?Listing => $invitation->campaign->listing)
            ->filter(fn (?Listing $listing): bool => $listing !== null && $listing->status === ListingStatus::Active);

        return $curated->concat($fromCampaigns)->unique('id')->take($limit)->values()
            ->map(fn (Listing $listing): array => ['listing' => $listing, 'referral_code' => $codes[$listing->id] ?? null]);
    }

    /**
     * The creator's accepted invitations on live campaigns, newest first.
     *
     * @return Collection<int, CampaignInvitation>
     */
    public function liveInvitations(MarketerProfile $creator): Collection
    {
        return CampaignInvitation::query()->where('marketer_profile_id', $creator->id)->where('status', InvitationStatus::Accepted)
            ->whereHas('campaign', fn ($query) => $query->whereIn('status', self::LIVE_CAMPAIGN))
            ->with(['campaign', ...ListingCards::relations('campaign.listing.')])->latest('id')->get();
    }

    /**
     * Studio rows: each curated pick with its live invitation (if any) and the
     * invitation's clicks and attributed orders.
     *
     * @return Collection<int, array{pick: MarketerPick, invitation: CampaignInvitation|null, clicks: int|null, orders: int|null}>
     */
    public function studioRows(MarketerProfile $creator): Collection
    {
        $picks = $creator->picks()->with('listing.variant.product', 'listing.vendor')->get();
        $invitations = $this->liveInvitations($creator)->keyBy(fn (CampaignInvitation $invitation): int => (int) $invitation->campaign->listing_id);
        $ids = $invitations->pluck('id')->all();
        $clicks = ReferralClick::query()->whereIn('invitation_id', $ids)->selectRaw('invitation_id, COUNT(*) as total')->groupBy('invitation_id')->pluck('total', 'invitation_id');
        $orders = MarketerConversion::query()->whereIn('invitation_id', $ids)->selectRaw('invitation_id, COUNT(DISTINCT order_id) as total')->groupBy('invitation_id')->pluck('total', 'invitation_id');

        return $picks->map(function (MarketerPick $pick) use ($invitations, $clicks, $orders): array {
            $invitation = $invitations->get($pick->listing_id);

            return [
                'pick' => $pick,
                'invitation' => $invitation,
                'clicks' => $invitation ? (int) ($clicks[$invitation->id] ?? 0) : null,
                'orders' => $invitation ? (int) ($orders[$invitation->id] ?? 0) : null,
            ];
        });
    }

    public function add(MarketerProfile $creator, Listing $listing): MarketerPick
    {
        return MarketerPick::query()->create([
            'marketer_profile_id' => $creator->id,
            'listing_id' => $listing->id,
            'position' => (int) MarketerPick::query()->where('marketer_profile_id', $creator->id)->max('position') + 1,
        ]);
    }

    /**
     * Move a pick one place up or down (swaps with its neighbour), then
     * renumber the creator's picks 1…n.
     */
    public function move(MarketerProfile $creator, MarketerPick $pick, string $direction): void
    {
        DB::connection($pick->getConnectionName())->transaction(function () use ($creator, $pick, $direction): void {
            $ordered = $creator->picks()->lockForUpdate()->get()->values();
            $index = $ordered->search(fn (MarketerPick $row): bool => $row->id === $pick->id);
            $target = $direction === 'up' ? $index - 1 : $index + 1;

            if ($index === false || $target < 0 || $target >= $ordered->count()) {
                return;
            }

            $ids = $ordered->pluck('id')->all();
            [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

            foreach ($ids as $position => $id) {
                MarketerPick::query()->whereKey($id)->update(['position' => $position + 1]);
            }
        });
    }
}
