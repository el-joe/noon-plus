<?php

namespace App\Domains\Campaigns\Support;

use App\Domains\Campaigns\Enums\AttributionModel;
use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Campaigns\Enums\InvitationStatus;
use App\Domains\Campaigns\Enums\ReferralStatus;
use App\Domains\Campaigns\Models\CampaignInvitation;
use App\Domains\Campaigns\Models\ReferralClick;
use App\Domains\Marketers\Support\MarketingSettings;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Referral clicks and attribution (SRS §11.8, MP §8).
 *
 * Validity matrix (ReferralCodeResolver): accepted + live campaign = active;
 * pending + live = pending (click kept, credited only once accepted);
 * rejected / timed out / replaced within `marketer_referral_grace_days` =
 * grace (credits only clicks recorded before it closed); later = expired;
 * campaign not live = campaign_closed; unknown code = unknown.
 *
 * Who earns an order item: a direct referral code given at checkout for
 * that listing; otherwise the attributable clicks on that listing inside
 * `marketer_attribution_window_days` by the same customer or visitor
 * token, chosen by the attribution model (campaign override, else country
 * `attribution_model`, default last_click): latest / earliest click, or
 * every distinct invitation in equal shares (linear). A marketer never
 * earns on their own purchase (self-referral block).
 */
class Attribution
{
    public function __construct(private MarketingSettings $settings, private Conversions $conversions) {}

    public function status(?CampaignInvitation $invitation, ?CarbonInterface $clickedAt = null, ?CarbonInterface $at = null): ReferralStatus
    {
        if ($invitation === null) {
            return ReferralStatus::Unknown;
        }

        $at ??= now();
        $campaign = $invitation->campaign;

        if (! $campaign->status->isLive()) {
            return ReferralStatus::CampaignClosed;
        }

        return match ($invitation->status) {
            InvitationStatus::Accepted => ReferralStatus::Active,
            InvitationStatus::Pending => ReferralStatus::Pending,
            InvitationStatus::Rejected, InvitationStatus::TimedOut => $this->graceStatus($invitation, $clickedAt, $at),
            default => ReferralStatus::Expired,
        };
    }

    /**
     * Record a `/r/{code}` visit when the code is active or pending.
     *
     * @return array{status: ReferralStatus, invitation: CampaignInvitation|null, click: ReferralClick|null}
     */
    public function recordClick(string $code, ?string $visitorToken, ?int $customerUserId, ?string $ip = null, ?string $userAgent = null): array
    {
        $invitation = CampaignInvitation::query()->where('referral_code', strtoupper($code))->with('campaign')->first();
        $status = $this->status($invitation);

        if (! in_array($status, [ReferralStatus::Active, ReferralStatus::Pending], true) || ($visitorToken === null && $customerUserId === null)) {
            return ['status' => $status, 'invitation' => $invitation, 'click' => null];
        }

        $click = ReferralClick::query()->create([
            'invitation_id' => $invitation->id,
            'campaign_id' => $invitation->campaign_id,
            'marketer_profile_id' => $invitation->marketer_profile_id,
            'listing_id' => $invitation->campaign->listing_id,
            'visitor_token' => $visitorToken === null ? null : mb_substr($visitorToken, 0, 64),
            'customer_user_id' => $customerUserId,
            'ip_address' => $ip,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 512),
            'clicked_at' => now(),
            'expires_at' => now()->addDays($this->settings->int('attribution_window_days')),
        ]);

        return ['status' => $status, 'invitation' => $invitation, 'click' => $click];
    }

    /**
     * Invitations that would earn a purchase of this listing, with weights.
     *
     * @return array<int, array{invitation: CampaignInvitation, attribution: string, weight: string}>
     */
    public function creditFor(int $listingId, int $customerUserId, ?string $visitorToken, ?string $directCode = null): array
    {
        if ($directCode !== null && $directCode !== '') {
            $direct = CampaignInvitation::query()->where('referral_code', strtoupper($directCode))->with('campaign', 'marketer')->first();

            if ($direct !== null && $direct->campaign->listing_id === $listingId && $this->status($direct) === ReferralStatus::Active && $direct->marketer->user_id !== $customerUserId) {
                return [['invitation' => $direct, 'attribution' => 'direct', 'weight' => '1']];
            }
        }

        $clicks = $this->clicks($listingId, $customerUserId, $visitorToken);

        if ($clicks->isEmpty()) {
            return [];
        }

        $model = AttributionModel::tryFrom((string) ($clicks->last()->invitation->campaign->attribution_model ?? ''))
            ?? AttributionModel::tryFrom((string) $this->settings->get('attribution_model'))
            ?? AttributionModel::LastClick;

        return match ($model) {
            AttributionModel::LastClick => [['invitation' => $clicks->last()->invitation, 'attribution' => $model->value, 'weight' => '1']],
            AttributionModel::FirstClick => [['invitation' => $clicks->first()->invitation, 'attribution' => $model->value, 'weight' => '1']],
            AttributionModel::Linear => $this->linear($clicks),
        };
    }

    /**
     * Marketers who would be credited for these cart listings (FR-CHK-4 gate).
     *
     * @param  array<int, int>  $listingIds
     * @return array<int, int> marketer profile ids
     */
    public function creditedMarketers(array $listingIds, int $customerUserId, ?string $visitorToken, ?string $directCode = null): array
    {
        $ids = [];

        foreach ($listingIds as $listingId) {
            foreach ($this->creditFor($listingId, $customerUserId, $visitorToken, $directCode) as $credit) {
                $ids[] = $credit['invitation']->marketer_profile_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * CampaignSaleRecorder: conversions for every item of a placed order.
     * Clicks recorded under the visitor token are tied to the customer.
     */
    public function attributeOrder(Order $order, ?string $visitorToken = null, ?string $directCode = null): int
    {
        if ($visitorToken !== null) {
            ReferralClick::query()->where('visitor_token', $visitorToken)->whereNull('customer_user_id')->update(['customer_user_id' => $order->customer_user_id]);
        }

        $recorded = 0;
        $items = OrderItem::query()->whereIn('sub_order_id', $order->subOrders()->select('id'))->with('subOrder')->orderBy('id')->get();

        foreach ($items as $item) {
            foreach ($this->creditFor((int) $item->listing_id, (int) $order->customer_user_id, $visitorToken, $directCode) as $credit) {
                if ($this->conversions->record($credit['invitation'], $item, $credit['attribution'], $credit['weight']) !== null) {
                    $recorded++;
                }
            }
        }

        return $recorded;
    }

    /**
     * Attributable clicks inside the window, oldest first.
     *
     * @return Collection<int, ReferralClick>
     */
    private function clicks(int $listingId, int $customerUserId, ?string $visitorToken): Collection
    {
        $since = now()->subDays($this->settings->int('attribution_window_days'));

        return ReferralClick::query()
            ->where('listing_id', $listingId)
            ->where('clicked_at', '>=', $since)
            ->where(fn ($query) => $query->where('customer_user_id', $customerUserId)->when($visitorToken !== null, fn ($inner) => $inner->orWhere('visitor_token', $visitorToken)))
            ->with('invitation.campaign', 'invitation.marketer')
            ->orderBy('clicked_at')->orderBy('id')
            ->get()
            ->filter(fn (ReferralClick $click): bool => $click->invitation->marketer->user_id !== $customerUserId
                && in_array($this->status($click->invitation, $click->clicked_at), [ReferralStatus::Active, ReferralStatus::Grace], true))
            ->values();
    }

    /**
     * @param  Collection<int, ReferralClick>  $clicks
     * @return array<int, array{invitation: CampaignInvitation, attribution: string, weight: string}>
     */
    private function linear(Collection $clicks): array
    {
        $invitations = $clicks->map->invitation->unique('id')->values();
        $weight = bcdiv('1', (string) $invitations->count(), 4);

        return $invitations->map(fn (CampaignInvitation $invitation): array => ['invitation' => $invitation, 'attribution' => AttributionModel::Linear->value, 'weight' => $weight])->all();
    }

    private function graceStatus(CampaignInvitation $invitation, ?CarbonInterface $clickedAt, CarbonInterface $at): ReferralStatus
    {
        $closedAt = $invitation->closed_at;

        if ($closedAt === null || $closedAt->addDays($this->settings->int('referral_grace_days'))->lessThan($at)) {
            return ReferralStatus::Expired;
        }

        return $clickedAt === null || $clickedAt->lessThan($closedAt) ? ReferralStatus::Grace : ReferralStatus::Expired;
    }

    /**
     * Statuses for which a live campaign still exists.
     *
     * @return array<int, CampaignStatus>
     */
    public static function liveStatuses(): array
    {
        return [CampaignStatus::Active, CampaignStatus::AutoApproved];
    }
}
