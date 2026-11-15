<?php

namespace App\Domains\Marketers\Support;

use App\Domains\Campaigns\Enums\InvitationStatus;
use App\Domains\Campaigns\Support\Invitations;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Marketers\Enums\MarketerJobType;
use App\Domains\Marketers\Enums\MarketerStatus;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Marketers\Models\MarketingAgency;
use App\Domains\Orders\Enums\ActorType;
use App\Models\User;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\StateTransition;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Marketer onboarding and global status (SRS §11.2, lifecycle catalogue
 * §7.4): register with job types → pending → admin approve | reject;
 * active ⇄ suspended (suspension cancels pending invitations). The user
 * gets one active `marketer:{type}` membership per job type in the
 * country so a pending or suspended marketer still reaches Creator Studio
 * (profile, contract, wallet); campaign actions check the global status
 * (FR-MKT-1). Rejection revokes the memberships. Agencies manage several
 * marketers.
 */
class MarketerProfiles
{
    public const AGENCY_TENANT = 'agency';

    public function __construct(private CountryContext $countryContext) {}

    /**
     * @param  array<int, string>  $jobTypes
     * @param  array<string, mixed>  $profile
     */
    public function register(User $user, array $jobTypes, array $profile = []): MarketerProfile
    {
        $types = array_values(array_unique(array_map(fn (string $type): string => MarketerJobType::from($type)->value, $jobTypes)));

        if ($types === []) {
            throw new TransitionRefused(__('Pick at least one job type.'));
        }

        if (MarketerProfile::query()->where('user_id', $user->id)->exists()) {
            throw new TransitionRefused(__('You already have a marketer profile in this country.'));
        }

        $name = (string) ($profile['display_name'] ?? $user->name);
        $marketer = MarketerProfile::query()->create([
            'user_id' => $user->id,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'display_name' => $name,
            'bio' => $profile['bio'] ?? null,
            'job_types' => $types,
            'global_status' => MarketerStatus::Pending,
            'whatsapp_phone' => $profile['whatsapp_phone'] ?? null,
            'socials' => $profile['socials'] ?? null,
            'followers' => (int) ($profile['followers'] ?? 0),
            'category_ids' => $profile['category_ids'] ?? null,
        ]);
        $this->history($marketer, null, MarketerStatus::Pending, ActorType::Marketer, $user->id);

        foreach ($types as $type) {
            $user->memberships()->firstOrCreate(
                ['role' => MarketerJobType::from($type)->membershipRole(), 'country_id' => $this->countryContext->current()->id],
                ['status' => 'active'],
            );
        }

        return $marketer;
    }

    public function transition(MarketerProfile $marketer, MarketerStatus $to, int $adminUserId, ?string $reason = null): MarketerProfile
    {
        $from = $marketer->global_status;

        if (! in_array($to, $from->allowedNext(), true)) {
            throw new TransitionRefused(__('A marketer cannot move from :from to :to.', ['from' => $from->value, 'to' => $to->value]));
        }

        if (in_array($to, [MarketerStatus::Suspended, MarketerStatus::Rejected], true) && blank($reason)) {
            throw new TransitionRefused(__('Give a reason.'));
        }

        $marketer->forceFill([
            'global_status' => $to,
            'status_reason' => $reason,
            'approved_at' => $to === MarketerStatus::Active && $marketer->approved_at === null ? now() : $marketer->approved_at,
        ])->save();
        $this->history($marketer, $from, $to, ActorType::Admin, $adminUserId, $reason);

        $membershipStatus = $to === MarketerStatus::Rejected ? 'revoked' : 'active';

        User::query()->find($marketer->user_id)?->memberships()
            ->where('country_id', $this->countryContext->current()->id)
            ->whereIn('role', array_map(fn (string $type): string => MarketerJobType::from($type)->membershipRole()->value, $marketer->job_types))
            ->update(['status' => $membershipStatus]);

        if ($to === MarketerStatus::Suspended) {
            foreach ($marketer->invitations()->where('status', 'pending')->get() as $invitation) {
                app(StateMachine::class)->move($invitation, InvitationStatus::Cancelled, ActorType::Admin, $adminUserId, 'Marketer suspended', ['closed_at' => now()]);
                app(Invitations::class)->release($invitation);
            }
        }

        return $marketer;
    }

    public function createAgency(User $owner, string $name): MarketingAgency
    {
        $agency = MarketingAgency::query()->create(['owner_user_id' => $owner->id, 'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)), 'status' => 'active']);
        $owner->memberships()->firstOrCreate(
            ['role' => MembershipRole::MarketingAgency, 'country_id' => $this->countryContext->current()->id, 'tenant_type' => self::AGENCY_TENANT, 'tenant_id' => $agency->id],
            ['status' => 'active'],
        );

        return $agency;
    }

    public function attachToAgency(MarketerProfile $marketer, MarketingAgency $agency): void
    {
        $marketer->forceFill(['agency_id' => $agency->id])->save();
    }

    /**
     * The profile a Creator Studio user acts as: their own in the country,
     * or (agency users) the managed marketer they switched to.
     */
    public function forUser(User $user, ?int $actingMarketerId = null): ?MarketerProfile
    {
        $own = MarketerProfile::query()->where('user_id', $user->id)->first();

        if ($actingMarketerId === null) {
            return $own ?? $this->agencyMarketers($user)->first();
        }

        return $this->agencyMarketers($user)->firstWhere('id', $actingMarketerId) ?? $own;
    }

    /**
     * @return Collection<int, MarketerProfile>
     */
    public function agencyMarketers(User $user): Collection
    {
        $agencyIds = $user->memberships()
            ->where('role', MembershipRole::MarketingAgency)->where('status', 'active')
            ->where('country_id', $this->countryContext->current()->id)
            ->where('tenant_type', self::AGENCY_TENANT)
            ->pluck('tenant_id');

        return $agencyIds->isEmpty() ? collect() : MarketerProfile::query()->whereIn('agency_id', $agencyIds)->orderBy('display_name')->get();
    }

    private function history(MarketerProfile $marketer, ?MarketerStatus $from, MarketerStatus $to, ActorType $actor, ?int $userId, ?string $reason = null): void
    {
        StateTransition::query()->create([
            'subject_type' => 'marketer_profile',
            'subject_id' => $marketer->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_type' => $actor,
            'actor_user_id' => $userId,
            'reason' => $reason,
        ]);
    }
}
