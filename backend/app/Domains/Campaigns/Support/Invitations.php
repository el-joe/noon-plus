<?php

namespace App\Domains\Campaigns\Support;

use App\Domains\Campaigns\Enums\InvitationSource;
use App\Domains\Campaigns\Enums\InvitationStatus;
use App\Domains\Campaigns\Models\CampaignInvitation;
use App\Domains\Campaigns\Models\MarketerCampaign;
use App\Domains\Contracts\Support\ContractGates;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Marketers\Enums\MarketerJobType;
use App\Domains\Marketers\Enums\MarketerStatus;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Marketers\Support\CommissionRules;
use App\Domains\Marketers\Support\MarketingSettings;
use App\Domains\Marketers\Support\WhatsAppMessenger;
use App\Domains\Orders\Enums\ActorType;
use App\Shared\Money\MoneyFactory;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Brick\Money\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Invitations (SRS §11.7, lifecycle catalogue §4.2).
 *
 * Dispatch: fresh 10-char code, expiry `marketer_invitation_timeout_hours`
 * (12), influencer fixed amount copied (reserved), commission rates
 * snapshotted, influencer sample planned, WhatsApp message with signed
 * accept/decline links. Accept needs an active marketer who accepted the
 * current onboarding contract (FR-MKT-1). Reject / timeout release the
 * reservation and search a replacement: same job type, active, same
 * country, never invited to this campaign, ≥ min accepted campaigns,
 * influencers whose own fixed amount fits the remaining budget; fairness
 * order = fewest invitations in 30 days, then fewest accepted campaigns.
 * Open campaigns do not replace affiliates/brokers.
 */
class Invitations
{
    public function __construct(
        private StateMachine $machine,
        private MarketingSettings $settings,
        private CommissionRules $rules,
        private ContractGates $contracts,
        private ReferralCodes $codes,
        private Samples $samples,
        private WhatsAppMessenger $whatsApp,
        private MoneyFactory $money,
        private CountryContext $countryContext,
    ) {}

    public function dispatch(MarketerCampaign $campaign, MarketerProfile $marketer, InvitationSource $source, bool $influencerSlot = false, ?Money $fixed = null, bool $planSample = true): CampaignInvitation
    {
        if ($marketer->global_status !== MarketerStatus::Active) {
            throw new TransitionRefused(__(':name is not an active marketer.', ['name' => $marketer->display_name]));
        }

        $autoAccept = in_array($source, [InvitationSource::Request, InvitationSource::Peer, InvitationSource::OpenJoin], true);
        $code = $this->codes->reserve($this->countryContext->current());
        $category = $campaign->category;

        $invitation = CampaignInvitation::query()->create([
            'campaign_id' => $campaign->id,
            'marketer_profile_id' => $marketer->id,
            'effective_type' => $influencerSlot ? MarketerJobType::Influencer->value : ($marketer->perSaleType()?->value ?? MarketerJobType::Affiliate->value),
            'source' => $source,
            'status' => InvitationStatus::Pending,
            'referral_code' => $code,
            'expires_at' => $autoAccept ? null : now()->addHours($this->settings->int('invitation_timeout_hours')),
            'influencer_fixed' => $influencerSlot && $fixed !== null ? $this->money->toStorage($fixed) : null,
            'influencer_fixed_status' => $influencerSlot && $fixed !== null && $fixed->isPositive() ? 'reserved' : 'none',
            'rate_snapshot' => $this->rules->resolve($marketer, $category, profileOnly: $source === InvitationSource::Peer),
        ]);
        $this->codes->bind($code, $invitation->id);
        $this->machine->created($invitation, ActorType::System);
        $invitation->setRelation('campaign', $campaign);

        if ($influencerSlot && $planSample) {
            $this->samples->planForInvitation($invitation);
        }

        if ($autoAccept) {
            return $this->machine->move($invitation, InvitationStatus::Accepted, ActorType::System, null, null, ['accepted_at' => now()]);
        }

        $this->sendWhatsApp($invitation, $marketer);

        return $invitation;
    }

    /**
     * Signed links for the WhatsApp message (ADR 5): accept / decline
     * without signing in, valid until the invitation expires.
     *
     * @return array{accept: string, decline: string, page: string}
     */
    public function signedLinks(CampaignInvitation $invitation): array
    {
        $expires = $invitation->expires_at ?? now()->addHours($this->settings->int('invitation_timeout_hours'));
        $parameters = ['country' => $this->countryContext->current()->code, 'invitation' => $invitation->id];

        return [
            'page' => URL::temporarySignedRoute('marketer.invitations.respond', $expires, $parameters),
            'accept' => URL::temporarySignedRoute('marketer.invitations.respond', $expires, $parameters + ['answer' => 'accept']),
            'decline' => URL::temporarySignedRoute('marketer.invitations.respond', $expires, $parameters + ['answer' => 'decline']),
        ];
    }

    /**
     * Admin resend with a cooldown (`marketer_whatsapp_resend_cooldown_minutes`).
     */
    public function resend(CampaignInvitation $invitation): void
    {
        if ($invitation->status !== InvitationStatus::Pending) {
            throw new TransitionRefused(__('Only a pending invitation can be resent.'));
        }

        if ($invitation->whatsapp_sent_at !== null && $invitation->whatsapp_sent_at->addMinutes($this->settings->int('whatsapp_resend_cooldown_minutes'))->isFuture()) {
            throw new TransitionRefused(__('Wait a few minutes before resending this invitation.'));
        }

        $this->sendWhatsApp($invitation, $invitation->marketer);
    }

    public function accept(CampaignInvitation $invitation, ?int $userId = null, ?string $note = null): CampaignInvitation
    {
        $marketer = $invitation->marketer;
        $campaign = $invitation->campaign;

        if (! $marketer->isActive()) {
            throw new TransitionRefused(__('Your marketer account must be active to accept campaigns.'));
        }

        if (! $this->contracts->marketerContractAccepted($marketer)) {
            throw new TransitionRefused(__('Accept the current marketer contract before accepting campaigns.'));
        }

        if (! $campaign->status->isLive()) {
            throw new TransitionRefused(__('This campaign is not live.'));
        }

        if ($invitation->expires_at !== null && $invitation->expires_at->isPast()) {
            throw new TransitionRefused(__('This invitation has expired.'));
        }

        return $this->machine->move($invitation, InvitationStatus::Accepted, ActorType::Marketer, $userId, null, [
            'accepted_at' => now(),
            'response_note' => $note === null ? null : mb_substr($note, 0, 255),
        ]);
    }

    public function reject(CampaignInvitation $invitation, ?int $userId = null, ?string $reason = null): ?CampaignInvitation
    {
        return $this->close($invitation, InvitationStatus::Rejected, ActorType::Marketer, $userId, $reason);
    }

    /**
     * ProcessInvitationTimeoutJob: pending invitations past `expires_at`.
     */
    public function timeOutExpired(): int
    {
        $count = 0;

        CampaignInvitation::query()->where('status', InvitationStatus::Pending)->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->orderBy('id')->each(function (CampaignInvitation $invitation) use (&$count): void {
                $this->close($invitation, InvitationStatus::TimedOut, ActorType::System, null, 'Invitation timed out');
                $count++;
            });

        return $count;
    }

    /**
     * Campaign closed: pending (and, unless settled, accepted) invitations are cancelled.
     */
    public function cancelForCampaign(MarketerCampaign $campaign, bool $includeAccepted): void
    {
        $statuses = $includeAccepted ? [InvitationStatus::Pending, InvitationStatus::Accepted] : [InvitationStatus::Pending];

        foreach ($campaign->invitations()->whereIn('status', $statuses)->get() as $invitation) {
            $this->machine->move($invitation, InvitationStatus::Cancelled, ActorType::System, null, null, ['closed_at' => now()]);
            $this->release($invitation);
        }
    }

    /**
     * Invite more (allowed while pending_admin or live): skips marketers
     * already pending/accepted, inactive ones and influencers who do not
     * fit the budget, each with a reason.
     *
     * @param  array<int, int>  $influencerIds
     * @return array<int, string> skipped marketer id => reason
     */
    public function inviteMore(MarketerCampaign $campaign, array $influencerIds): array
    {
        $skipped = [];

        foreach (MarketerProfile::query()->whereKey($influencerIds)->get() as $marketer) {
            $reason = $this->skipReason($campaign, $marketer);

            if ($reason !== null) {
                $skipped[$marketer->id] = $reason;

                continue;
            }

            $fixed = $this->influencerFixed($marketer, $campaign);

            if ($campaign->status->isLive()) {
                if (! $this->reserve($campaign, $fixed)) {
                    $skipped[$marketer->id] = __('Does not fit the remaining budget.');

                    continue;
                }

                $this->dispatch($campaign, $marketer, InvitationSource::InviteMore, true, $fixed);
            } else {
                $planned = $campaign->planned_marketers ?? [];

                if (! $this->reserve($campaign, $fixed)) {
                    $skipped[$marketer->id] = __('Does not fit the remaining budget.');

                    continue;
                }

                $planned[] = ['marketer_profile_id' => $marketer->id, 'influencer_fixed' => (string) $fixed->getAmount()];
                $campaign->forceFill(['planned_marketers' => $planned])->save();
            }
        }

        return $skipped;
    }

    /**
     * Origin F: an affiliate or broker joins a live campaign that is open to them.
     */
    public function joinOpen(MarketerCampaign $campaign, MarketerProfile $marketer): CampaignInvitation
    {
        if (! $campaign->status->isLive() || ! $campaign->open_to_affiliates) {
            throw new TransitionRefused(__('This campaign is not open to affiliates and brokers.'));
        }

        if (! $marketer->hasJobType(MarketerJobType::Affiliate) && ! $marketer->hasJobType(MarketerJobType::Broker)) {
            throw new TransitionRefused(__('Only affiliates and brokers can join open campaigns.'));
        }

        if (! $this->contracts->marketerContractAccepted($marketer)) {
            throw new TransitionRefused(__('Accept the current marketer contract before accepting campaigns.'));
        }

        if ($campaign->invitations()->where('marketer_profile_id', $marketer->id)->whereIn('status', [InvitationStatus::Pending, InvitationStatus::Accepted])->exists()) {
            throw new TransitionRefused(__('You already take part in this campaign.'));
        }

        return $this->dispatch($campaign, $marketer, InvitationSource::OpenJoin);
    }

    /**
     * The influencer fixed amount (snapshot source order: profile rule for
     * the category and its parents, profile vertical rule, platform default).
     */
    public function influencerFixed(MarketerProfile $marketer, MarketerCampaign $campaign): Money
    {
        return $this->money->of($this->rules->resolve($marketer, $campaign->category)['influencer_fixed'] ?? '0', $campaign->currency);
    }

    /**
     * Reserve an influencer fixed amount from the budget under a row lock.
     */
    public function reserve(MarketerCampaign $campaign, Money $fixed): bool
    {
        return DB::connection($campaign->getConnectionName())->transaction(function () use ($campaign, $fixed): bool {
            $locked = MarketerCampaign::query()->lockForUpdate()->findOrFail($campaign->id);

            if ($locked->budget !== null) {
                $remaining = $this->money->of((string) $locked->budget, $locked->currency)
                    ->minus($this->money->of((string) $locked->budget_spent, $locked->currency))
                    ->minus($this->money->of((string) $locked->influencer_fixed_reserved, $locked->currency));

                if ($fixed->isGreaterThan($remaining)) {
                    return false;
                }
            }

            $locked->forceFill(['influencer_fixed_reserved' => $this->money->toStorage($this->money->of((string) $locked->influencer_fixed_reserved, $locked->currency)->plus($fixed))])->save();
            $campaign->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /**
     * Give a reserved influencer amount back to the budget.
     */
    public function release(CampaignInvitation $invitation): void
    {
        if ($invitation->influencer_fixed_status !== 'reserved') {
            return;
        }

        DB::connection($invitation->getConnectionName())->transaction(function () use ($invitation): void {
            $campaign = MarketerCampaign::query()->lockForUpdate()->findOrFail($invitation->campaign_id);
            $reserved = $this->money->of((string) $campaign->influencer_fixed_reserved, $campaign->currency)->minus($this->money->of((string) $invitation->influencer_fixed, $campaign->currency));
            $campaign->forceFill(['influencer_fixed_reserved' => $this->money->toStorage($reserved->isNegative() ? $this->money->zero($campaign->currency) : $reserved)])->save();
            $invitation->forceFill(['influencer_fixed_status' => 'released'])->save();
        });
    }

    /**
     * Fairness-ordered candidates for a replacement.
     *
     * @return Collection<int, MarketerProfile>
     */
    public function replacementCandidates(CampaignInvitation $invitation): Collection
    {
        $campaign = $invitation->campaign;
        $type = MarketerJobType::from($invitation->effective_type);
        $invited = $campaign->invitations()->pluck('marketer_profile_id')->all();
        $minAccepted = $this->settings->int('replacement_min_accepted');

        return MarketerProfile::query()
            ->where('global_status', MarketerStatus::Active)
            ->whereJsonContains('job_types', $type->value)
            ->whereNotIn('id', $invited)
            ->when($campaign->requested_by_marketer_id !== null, fn ($query) => $query->where('id', '!=', $campaign->requested_by_marketer_id))
            ->withCount([
                'invitations as recent_invitations' => fn ($query) => $query->where('created_at', '>=', now()->subDays(30)),
                'invitations as accepted_campaigns' => fn ($query) => $query->where('status', InvitationStatus::Accepted),
            ])
            ->get()
            ->filter(fn (MarketerProfile $candidate): bool => $candidate->accepted_campaigns >= $minAccepted)
            ->sortBy([['recent_invitations', 'asc'], ['accepted_campaigns', 'asc'], ['id', 'asc']])
            ->values();
    }

    private function close(CampaignInvitation $invitation, InvitationStatus $to, ActorType $actor, ?int $userId, ?string $reason): ?CampaignInvitation
    {
        $this->machine->move($invitation, $to, $actor, $userId, $reason, ['closed_at' => now(), 'response_note' => $reason === null ? null : mb_substr($reason, 0, 255)]);
        $this->release($invitation);

        return $this->replace($invitation->refresh());
    }

    private function replace(CampaignInvitation $invitation): ?CampaignInvitation
    {
        $campaign = $invitation->campaign()->firstOrFail();
        $influencerSlot = $invitation->effective_type === MarketerJobType::Influencer->value;

        if (! $campaign->status->isLive() || (! $influencerSlot && $campaign->open_to_affiliates) || $invitation->source === InvitationSource::Peer) {
            $this->samples->cancelOpen($campaign, $invitation->id);

            return null;
        }

        foreach ($this->replacementCandidates($invitation) as $candidate) {
            $fixed = $influencerSlot ? $this->influencerFixed($candidate, $campaign) : null;

            if ($fixed !== null && ! $this->reserve($campaign, $fixed)) {
                continue;
            }

            $replacement = $this->dispatch($campaign, $candidate, InvitationSource::Replacement, $influencerSlot, $fixed, planSample: false);
            $replacement->forceFill(['replaced_invitation_id' => $invitation->id])->save();
            $invitation->forceFill(['replaced_at' => now()])->save();

            if ($influencerSlot && ! $this->samples->handOver($invitation, $replacement)) {
                $this->samples->planForInvitation($replacement);
            }

            return $replacement;
        }

        $this->samples->cancelOpen($campaign, $invitation->id);

        return null;
    }

    private function skipReason(MarketerCampaign $campaign, MarketerProfile $marketer): ?string
    {
        if (! $campaign->status->isLive() && $campaign->status->value !== 'pending_admin') {
            return __('The campaign does not take new marketers.');
        }

        if (! $marketer->isActive()) {
            return __('Not an active marketer.');
        }

        if (! $marketer->hasJobType(MarketerJobType::Influencer)) {
            return __('Not an influencer.');
        }

        $alreadyPlanned = collect($campaign->planned_marketers ?? [])->contains('marketer_profile_id', $marketer->id);

        if ($alreadyPlanned || $campaign->invitations()->where('marketer_profile_id', $marketer->id)->whereIn('status', [InvitationStatus::Pending, InvitationStatus::Accepted])->exists()) {
            return __('Already invited.');
        }

        return null;
    }

    private function sendWhatsApp(CampaignInvitation $invitation, MarketerProfile $marketer): void
    {
        $links = $this->signedLinks($invitation);
        $sent = $this->whatsApp->send($marketer->whatsapp_phone, __('Nawy campaign invitation ":title". Accept: :accept — Decline: :decline', [
            'title' => $invitation->campaign->title,
            'accept' => $links['accept'],
            'decline' => $links['decline'],
        ]));

        if ($sent) {
            $invitation->forceFill(['whatsapp_sent_at' => now()])->save();
        }
    }
}
